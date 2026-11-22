import assert from 'node:assert/strict';
import test from 'node:test';

import { errorMessages, limitUsage, planLevels, planSummary, warningItems, warningMessages } from './plan.js';

const plan = {
    levels: [
        { sequence: 1, side: 'buy', price: '49900', quantity: '0.001', notional: '49.9' },
        { sequence: 2, side: 'sell', price: '50100', quantity: '0.001', notional: '50.1' },
    ],
    order_count: 2,
    buy_notional: '49.9',
    sell_notional: '50.1',
    levels_per_side: 1,
};

const limits = {
    max_open_orders: 20,
    max_position_notional: '1000',
};

test('formats level prices and notionals as money and keeps quantities raw', () => {
    const rows = planLevels(plan);

    assert.deepEqual(rows, [
        {
            key: 'buy-1',
            sequence: 1,
            side: 'buy',
            price: '49900.00',
            quantity: '0.001',
            notional: '49.90',
        },
        {
            key: 'sell-2',
            sequence: 2,
            side: 'sell',
            price: '50100.00',
            quantity: '0.001',
            notional: '50.10',
        },
    ]);
});

test('formats the plan totals as money', () => {
    assert.deepEqual(planSummary(plan), {
        orderCount: 2,
        levelsPerSide: 1,
        buyNotional: '49.90',
        sellNotional: '50.10',
    });
});

test('reports limit usage as display strings', () => {
    assert.deepEqual(limitUsage(plan, limits), {
        orders: '2 of 20',
        position: '49.90 of 1000.00',
    });
});

test('renders warning and error messages in order', () => {
    assert.deepEqual(
        warningMessages([
            { code: 'plan.max_open_orders', message: 'This ladder has more orders than your limit.' },
            { code: 'plan.unfunded_sells', message: 'Sell levels need base inventory.' },
        ]),
        [
            'This ladder has more orders than your limit.',
            'Sell levels need base inventory.',
        ],
    );

    assert.deepEqual(errorMessages([{ code: 'plan.invalid_configuration', message: 'Spacing is invalid.' }]), [
        'Spacing is invalid.',
    ]);
});

test('keeps warning codes so the UI can link to the right setting', () => {
    assert.deepEqual(
        warningItems([{ code: 'plan.max_position_notional', message: 'Position is over the limit.' }]),
        [{ code: 'plan.max_position_notional', message: 'Position is over the limit.' }],
    );
});

test('handles a missing plan', () => {
    assert.deepEqual(planLevels(null), []);
    assert.equal(planSummary(null).orderCount, 0);
    assert.deepEqual(warningMessages(null), []);
    assert.deepEqual(errorMessages(undefined), []);
});
