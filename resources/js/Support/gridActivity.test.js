import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildChartData,
    buildTableRows,
    formatDateTime,
    formatDuration,
    formatPrice,
    formatTime,
    hasActivity,
    rangeLabel,
} from './gridActivity.js';

const activity = {
    price: {
        points: [
            { t: 1700000000000, price: '100.5' },
            { t: 1700000300000, price: '101.25' },
        ],
    },
    grids: [
        {
            id: 1,
            started_at: '2023-11-14T22:00:00+00:00',
            ended_at: '2023-11-14T23:00:00+00:00',
            levels: [
                { price: '99.0000000000000000' },
                { price: '102.0000000000000000' },
            ],
        },
    ],
    fills: [{ id: 1, price: '100.5', filled_at: '2023-11-14T22:13:20+00:00', side: 'buy' }],
    cancels: [{ id: 2, price: '101.25', closed_at: '2023-11-14T22:20:00+00:00', side: 'sell' }],
};

test('labels ranges', () => {
    assert.equal(rangeLabel('1h'), '1 hour');
    assert.equal(rangeLabel('6h'), '6 hours');
    assert.equal(rangeLabel('24h'), '24 hours');
    assert.equal(rangeLabel('7d'), '7 days');
});

test('formats UTC times without a date adapter', () => {
    assert.equal(formatTime(1700000000000), '22:13');
    assert.equal(formatDateTime(1700000000000), 'Nov 14 22:13');
});

test('formats prices with magnitude-aware precision', () => {
    assert.equal(formatPrice('50000.0000000000000000'), '50,000');
    assert.equal(formatPrice('1234.567'), '1,234.57');
    assert.equal(formatPrice('101.5'), '101.5');
    assert.equal(formatPrice('1.234567'), '1.2346');
    assert.equal(formatPrice('0.00012345'), '0.00012345');
    assert.equal(formatPrice(null), '—');
    assert.equal(formatPrice('not-a-number'), '—');
});

test('formats durations for the churn summary', () => {
    assert.equal(formatDuration(45), '45s');
    assert.equal(formatDuration(1200), '20m');
    assert.equal(formatDuration(3900), '1h 5m');
    assert.equal(formatDuration(3599), '1h');
    assert.equal(formatDuration(7199), '2h');
    assert.equal(formatDuration(86399), '24h');
    assert.equal(formatDuration(null), '—');
});

test('builds chart points and includes grid levels in the y domain', () => {
    const data = buildChartData(activity);

    assert.deepEqual(data.price, [
        { x: 1700000000000, y: 100.5 },
        { x: 1700000300000, y: 101.25 },
    ]);
    assert.equal(data.fills.length, 1);
    assert.equal(data.fills[0].y, 100.5);
    assert.equal(data.cancels.length, 1);
    assert.equal(data.yMin, 99);
    assert.equal(data.yMax, 102);
});

test('keeps a tiny padding relative to a low single-value domain', () => {
    const data = buildChartData({
        price: { points: [{ t: 1700000000000, price: '0.000012' }] },
        grids: [],
        fills: [],
        cancels: [],
    });

    assert.equal(data.yMin, 0.000012);
    assert.equal(data.yMax, 0.000012);
    assert.ok(data.yPadding > 0);
    assert.ok(data.yPadding < 0.001);
});

test('builds a globally chronological table from mixed timestamp shapes', () => {
    const rows = buildTableRows(activity);

    assert.deepEqual(rows.map((row) => row.kind), ['Price', 'Fill', 'Price', 'Cancel']);
    assert.deepEqual(rows.map((row) => row.key), [
        'price-1700000000000',
        'fill-1',
        'price-1700000300000',
        'cancel-2',
    ]);
    assert.deepEqual(buildTableRows(activity, { showFills: false, showCancels: false }).map((row) => row.kind), [
        'Price',
        'Price',
    ]);
});

test('handles empty activity and absent price history', () => {
    assert.equal(hasActivity(activity), true);
    assert.equal(hasActivity({ price: { points: [] }, grids: [], fills: [], cancels: [] }), false);

    const data = buildChartData({ price: { points: [] }, grids: [], fills: [], cancels: [] });
    assert.deepEqual(data.price, []);
    assert.equal(data.yMin, null);
    assert.equal(data.yMax, null);
});
