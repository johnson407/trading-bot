import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { hiddenFields, marketNotice, mergeDerivedValues, previewPayload } from './botSetup.js';

const derived = {
    strategy_key: 'simple-grid',
    level_count: 6,
    spacing_type: 'geometric',
    spacing_value: '0.004',
    order_quantity: '0.0026666666666667',
};

test('applying preset values keeps the user-entered fields intact', () => {
    const form = {
        name: 'My bot',
        capital_allocation: '1000',
        center_price: '50000',
        level_count: 1,
        spacing_type: 'arithmetic',
        spacing_value: '1',
        order_quantity: '1',
    };

    const next = mergeDerivedValues(form, derived);

    assert.equal(next.name, 'My bot');
    assert.equal(next.capital_allocation, '1000');
    assert.equal(next.center_price, '50000');
    assert.equal(next.strategy_key, 'simple-grid');
    assert.equal(next.level_count, 6);
    assert.equal(next.spacing_type, 'geometric');
    assert.equal(next.spacing_value, '0.004');
    assert.equal(next.order_quantity, '0.0026666666666667');
});

test('switching to advanced mode keeps the derived values', () => {
    const form = mergeDerivedValues({ name: 'My bot' }, derived);
    const payload = previewPayload({ setupMode: 'advanced', form, presetKey: 'balanced' });

    assert.equal(payload.strategy_key, 'simple-grid');
    assert.equal(payload.level_count, 6);
    assert.equal(payload.spacing_type, 'geometric');
    assert.equal(payload.spacing_value, '0.004');
    assert.equal(payload.order_quantity, '0.0026666666666667');
    assert.equal('preset_key' in payload, false);
});

test('quick setup preview sends the preset key and lets the server derive values', () => {
    const payload = previewPayload({
        setupMode: 'quick',
        form: {
            exchange: 'binance',
            symbol: 'BTC/USDT',
            capital_allocation: '1000',
            center_price: '50000',
        },
        presetKey: 'balanced',
    });

    assert.deepEqual(payload, {
        preset_key: 'balanced',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        capital_allocation: '1000',
        center_price: '50000',
    });
});

test('advanced preview includes dynamic-grid hysteresis', () => {
    const payload = previewPayload({
        setupMode: 'advanced',
        form: {
            strategy_key: 'dynamic-grid',
            level_count: 6,
            order_quantity: '1',
            min_spacing: '50',
            max_spacing: '500',
            spacing_hysteresis_percent: '20',
        },
    });

    assert.equal(payload.spacing_hysteresis_percent, '20');
});

test('dynamic grids hide the shared spacing fields', () => {
    assert.deepEqual(hiddenFields('dynamic-grid'), ['spacing_type', 'spacing_value']);
    assert.deepEqual(hiddenFields('simple-grid'), []);
});

test('market data failure keeps a manual center price usable', () => {
    assert.equal(marketNotice(true), null);
    assert.match(marketNotice(false), /unavailable/i);

    const payload = previewPayload({
        setupMode: 'quick',
        form: { exchange: 'binance', symbol: 'BTC/USDT', capital_allocation: '1000', center_price: '50000' },
        presetKey: 'balanced',
    });

    assert.equal(payload.center_price, '50000');
});

test('create page keeps the live confirmation strings', () => {
    const source = readFileSync(new URL('../Pages/Bots/Create.vue', import.meta.url), 'utf8');

    assert.match(source, /live_confirmation: ''/);
    assert.ok(source.includes('form.errors.live_confirmation'));
    assert.ok(source.includes('${form.name} on ${form.exchange}'));
    assert.ok(source.includes('expectedConfirmation'));
});
