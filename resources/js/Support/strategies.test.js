import assert from 'node:assert/strict';
import test from 'node:test';

import { strategyMeta, strategyOptions } from './strategies.js';

test('describes every built-in strategy with a human label', () => {
    const labels = strategyOptions(['simple-grid', 'trailing-grid', 'martingale-grid', 'dynamic-grid']);

    assert.deepEqual(
        labels.map((strategy) => strategy.label),
        ['Simple grid', 'Trailing grid', 'Martingale grid', 'Dynamic grid'],
    );

    for (const strategy of labels) {
        assert.ok(strategy.description.length > 20);
    }
});

test('falls back to the raw key for an unknown strategy', () => {
    assert.deepEqual(strategyMeta('custom-grid'), {
        label: 'custom-grid',
        description: '',
    });
});
