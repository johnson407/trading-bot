const shared = {
    lastFetchedAt: 0,
    lastStatus: null,
    inFlight: null,
};

export function resetEngineStatusCache() {
    shared.lastFetchedAt = 0;
    shared.lastStatus = null;
    shared.inFlight = null;
}

const lastSeen = (value) => (value ? new Date(value).toLocaleString() : 'never');

const engineStatus = (heartbeat) => {
    if (heartbeat.available === true) {
        return {
            state: 'up',
            tone: 'ok',
            label: 'Engine up',
            detail: `Heartbeat fresh; not a full readiness check. Last seen ${lastSeen(heartbeat.last_seen_at)}.`,
        };
    }

    if (heartbeat.available === false) {
        return {
            state: 'down',
            tone: 'danger',
            label: 'Engine down',
            detail: heartbeat.unreachable
                ? `Status unavailable; last seen ${lastSeen(heartbeat.last_seen_at)}. Warning only; bots are not paused automatically.`
                : `Last seen ${lastSeen(heartbeat.last_seen_at)}. Warning only; bots are not paused automatically.`,
        };
    }

    return {
        state: 'unknown',
        tone: 'muted',
        label: 'Engine unknown',
        detail: 'Heartbeat status is unavailable.',
    };
};

const feedStatus = (heartbeat) => {
    const unknown = (detail) => ({
        state: 'unknown',
        tone: 'muted',
        label: 'Feed unknown',
        detail,
    });

    if (heartbeat.available !== true) {
        return unknown('Engine heartbeat unavailable; feed state is unknown.');
    }

    const marketData = heartbeat.market_data;

    if (!marketData || typeof marketData !== 'object') {
        return unknown('The engine did not report market-data status.');
    }

    if (marketData.configured === false) {
        return {
            state: 'off',
            tone: 'muted',
            label: 'Feed off',
            detail: 'Market-data feed not configured; ticks use REST snapshots.',
        };
    }

    if (marketData.configured === null || marketData.configured === undefined) {
        return unknown('Market-data configuration is unknown.');
    }

    if (marketData.mode === 'idle') {
        return {
            state: 'idle',
            tone: 'warn',
            label: 'Feed idle',
            detail: 'Configured with no symbols; ticks use REST snapshots.',
        };
    }

    if (marketData.degraded === true || marketData.mode === 'rest') {
        return {
            state: 'rest',
            tone: 'warn',
            label: 'REST fallback',
            detail: marketData.reason || 'Using REST snapshots.',
        };
    }

    if (marketData.mode === 'websocket' && marketData.degraded === false) {
        return {
            state: 'live',
            tone: 'ok',
            label: 'Feed live',
            detail: marketData.symbols > 0
                ? `${marketData.symbols} symbols over WebSocket.`
                : 'WebSocket feed healthy.',
        };
    }

    if (marketData.mode === 'websocket' && marketData.degraded === null) {
        return unknown('Feed health is unknown.');
    }

    return unknown('Market-data status is unknown.');
};

const liveStatus = (heartbeat) => {
    const unknown = (detail) => ({
        state: 'unknown',
        tone: 'muted',
        label: 'Live unknown',
        detail,
    });

    if (heartbeat.available !== true) {
        return unknown('Engine heartbeat unavailable; live gate state is unknown.');
    }

    if (heartbeat.live_trading === true) {
        return {
            state: 'on',
            tone: 'danger',
            label: 'LIVE ON',
            detail: 'Live trading is enabled by the engine gate.',
        };
    }

    if (heartbeat.live_trading === false) {
        return {
            state: 'off',
            tone: 'ok',
            label: 'Live orders blocked',
            detail: "The engine's live gate is off; live orders are blocked.",
        };
    }

    return unknown('The engine did not report the live gate.');
};

export function describeEngineStatus(heartbeat = {}) {
    return {
        engine: engineStatus(heartbeat),
        feed: feedStatus(heartbeat),
        live: liveStatus(heartbeat),
    };
}

export function createEngineStatusPoller({
    fetcher,
    intervalMs = 10_000,
    now = Date.now,
    onStatus,
    onError,
} = {}) {
    if (typeof fetcher !== 'function') {
        throw new TypeError('createEngineStatusPoller requires a fetcher function.');
    }

    let stopped = false;
    let timer = null;

    const emit = (status) => {
        if (!stopped) {
            onStatus?.(status);
        }
    };

    const poll = async () => {
        if (stopped) {
            return null;
        }

        if (shared.inFlight) {
            const status = await shared.inFlight;
            emit(status);

            return status;
        }

        if (now() - shared.lastFetchedAt < intervalMs && shared.lastStatus) {
            emit(shared.lastStatus);
            return shared.lastStatus;
        }

        shared.lastFetchedAt = now();
        shared.inFlight = (async () => {
            try {
                const response = await fetcher();
                const body = await response.json().catch(() => null);
                const status = body && typeof body === 'object' ? { ...body } : {};

                if (typeof status.available !== 'boolean') {
                    status.available = false;
                }

                shared.lastStatus = status;
                emit(status);

                return status;
            } catch (error) {
                const status = {
                    available: false,
                    unreachable: true,
                    last_seen_at: shared.lastStatus?.last_seen_at ?? null,
                };
                shared.lastStatus = status;
                onError?.(error);
                emit(status);

                return status;
            } finally {
                shared.inFlight = null;
            }
        })();

        return shared.inFlight;
    };

    poll();
    timer = setInterval(poll, intervalMs);

    return {
        poll,
        stop() {
            stopped = true;

            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }
        },
    };
}
