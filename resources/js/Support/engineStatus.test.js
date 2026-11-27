import assert from 'node:assert/strict';
import test from 'node:test';

import {
    createEngineStatusPoller,
    describeEngineStatus,
    resetEngineStatusCache,
} from './engineStatus.js';

test.beforeEach(() => {
    resetEngineStatusCache();
});

const healthy = {
    available: true,
    last_seen_at: '2026-11-26T12:00:00+00:00',
    live_trading: false,
    market_data: {
        configured: true,
        mode: 'websocket',
        degraded: false,
        reason: '',
        symbols: 3,
        last_message_at: '2026-11-26T11:59:59+00:00',
    },
};

test('describes a healthy engine, live feed, and blocked live gate', () => {
    const status = describeEngineStatus(healthy);

    assert.equal(status.engine.state, 'up');
    assert.equal(status.feed.state, 'live');
    assert.equal(status.live.state, 'off');
    assert.equal(status.live.label, 'Live orders blocked');
});

test('does not claim feed or live state when the heartbeat is unavailable', () => {
    const status = describeEngineStatus({
        ...healthy,
        available: false,
        live_trading: true,
    });

    assert.equal(status.engine.state, 'down');
    assert.equal(status.feed.state, 'unknown');
    assert.equal(status.live.state, 'unknown');
});

test('describes feed off, idle, REST fallback, and partial status', () => {
    assert.equal(
        describeEngineStatus({ ...healthy, market_data: { ...healthy.market_data, configured: false } }).feed.state,
        'off',
    );
    assert.equal(
        describeEngineStatus({ ...healthy, market_data: { ...healthy.market_data, mode: 'idle' } }).feed.state,
        'idle',
    );
    assert.equal(
        describeEngineStatus({ ...healthy, market_data: { ...healthy.market_data, mode: 'rest', degraded: true } }).feed.state,
        'rest',
    );
    assert.equal(
        describeEngineStatus({ ...healthy, market_data: { configured: null } }).feed.state,
        'unknown',
    );
    assert.equal(
        describeEngineStatus({ ...healthy, market_data: null }).feed.state,
        'unknown',
    );
});

test('describes live on and unknown gate states', () => {
    assert.equal(describeEngineStatus({ ...healthy, live_trading: true }).live.state, 'on');
    assert.equal(describeEngineStatus({ ...healthy, live_trading: null }).live.state, 'unknown');
});

test('parses a 503 heartbeat body as an unavailable engine', async () => {
    const seen = [];
    let calls = 0;
    const poller = createEngineStatusPoller({
        fetcher: async () => {
            calls += 1;

            return {
                status: 503,
                json: async () => ({ available: false, last_seen_at: null }),
            };
        },
        now: () => 1_000,
        onStatus: (status) => seen.push(status),
    });

    await poller.poll();
    poller.stop();

    assert.equal(calls, 1);
    assert.equal(seen.at(-1).available, false);
});

test('reports network failure as an unreachable engine', async () => {
    const seen = [];
    const errors = [];
    const poller = createEngineStatusPoller({
        fetcher: async () => {
            throw new Error('network down');
        },
        now: () => 1_000,
        onStatus: (status) => seen.push(status),
        onError: (error) => errors.push(error),
    });

    await poller.poll();
    poller.stop();

    assert.equal(errors.length, 1);
    assert.equal(seen.at(-1).available, false);
    assert.equal(seen.at(-1).unreachable, true);
});

test('throttles requests and reuses the cached status across remounts', async () => {
    let calls = 0;
    let current = 1_000;
    const poller = createEngineStatusPoller({
        fetcher: async () => {
            calls += 1;

            return { status: 200, json: async () => ({ ...healthy }) };
        },
        intervalMs: 10_000,
        now: () => current,
    });

    await poller.poll();
    await poller.poll();
    assert.equal(calls, 1);

    current += 10_000;
    await poller.poll();
    poller.stop();
    assert.equal(calls, 2);
});

test('deduplicates an in-flight request', async () => {
    let calls = 0;
    let resolveFetch;
    const response = new Promise((resolve) => {
        resolveFetch = resolve;
    });
    const poller = createEngineStatusPoller({
        fetcher: async () => {
            calls += 1;

            return response;
        },
        now: () => 1_000,
    });

    const first = poller.poll();
    const second = poller.poll();

    resolveFetch({ status: 200, json: async () => ({ ...healthy }) });
    await Promise.all([first, second]);
    poller.stop();

    assert.equal(calls, 1);
});

test('delivers an in-flight result to a newly mounted poller', async () => {
    let calls = 0;
    let resolveFetch;
    const response = new Promise((resolve) => {
        resolveFetch = resolve;
    });
    const firstSeen = [];
    const secondSeen = [];
    const fetcher = async () => {
        calls += 1;

        return response;
    };

    const first = createEngineStatusPoller({
        fetcher,
        now: () => 1_000,
        onStatus: (status) => firstSeen.push(status),
    });
    const second = createEngineStatusPoller({
        fetcher,
        now: () => 1_000,
        onStatus: (status) => secondSeen.push(status),
    });

    const secondPoll = second.poll();
    resolveFetch({ status: 200, json: async () => ({ ...healthy }) });
    await secondPoll;
    first.stop();
    second.stop();

    assert.equal(calls, 1);
    assert.equal(firstSeen.at(-1).available, true);
    assert.equal(secondSeen.at(-1).available, true);
});

test('makes engine-down and live-gate copy explicit', () => {
    const down = describeEngineStatus({
        available: false,
        unreachable: true,
        last_seen_at: '2026-11-26T12:00:00+00:00',
    });
    const up = describeEngineStatus(healthy);

    assert.match(down.engine.detail, /Warning only/);
    assert.match(down.engine.detail, /not paused automatically/);
    assert.match(up.engine.detail, /not a full readiness check/);
    assert.match(up.live.detail, /engine's live gate/);
});

test('stops polling and ignores later calls', async () => {
    let calls = 0;
    const poller = createEngineStatusPoller({
        fetcher: async () => {
            calls += 1;

            return { status: 200, json: async () => ({ ...healthy }) };
        },
        now: () => 1_000,
    });

    await poller.poll();
    poller.stop();
    assert.equal(await poller.poll(), null);
    assert.equal(calls, 1);
});
