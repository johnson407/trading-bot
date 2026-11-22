import assert from 'node:assert/strict';
import test from 'node:test';

import { formatMoney, formatSignedMoney, submittedMoney } from './money.js';

test('formats fixed-scale whole amounts', () => {
    assert.equal(formatMoney('500.00000000'), '500.00');
    assert.equal(formatMoney('1000.0000000000000000'), '1000.00');
});

test('rounds half up to two decimals', () => {
    assert.equal(formatMoney('500.126'), '500.13');
    assert.equal(formatMoney('1.005'), '1.01');
    assert.equal(formatMoney('1.004'), '1.00');
});

test('formats negatives and suppresses negative zero', () => {
    assert.equal(formatMoney('-25.5'), '-25.50');
    assert.equal(formatMoney('-0.001'), '0.00');
});

test('formats zero and empty values', () => {
    assert.equal(formatMoney('0'), '0.00');
    assert.equal(formatMoney(0), '0.00');
    assert.equal(formatMoney(null), '0.00');
    assert.equal(formatMoney(''), '0.00');
    assert.equal(formatMoney(undefined), '0.00');
});

test('formats numeric and exponent inputs without scientific notation', () => {
    assert.equal(formatMoney(1e-7), '0.00');
    assert.equal(formatMoney('1.5e2'), '150.00');
    assert.equal(formatMoney('1.234e2'), '123.40');
    assert.equal(formatMoney('1e99999999999999999999'), '0.00');
});

test('handles whitespace, signs, grouping, and non-finite values', () => {
    assert.equal(formatMoney(' 500.00 '), '500.00');
    assert.equal(formatMoney('+25.5'), '25.50');
    assert.equal(formatMoney('1234567.891'), '1234567.89');
    assert.equal(formatMoney(Number.NaN), '0.00');
    assert.equal(formatMoney(Number.POSITIVE_INFINITY), '0.00');
    assert.equal(formatMoney(Number.NEGATIVE_INFINITY), '0.00');
});

test('rounds negative half-cents away from zero', () => {
    assert.equal(formatMoney('-0.005'), '-0.01');
    assert.equal(formatMoney('-0.004'), '0.00');
});

test('adds a plus sign for positive signed amounts only', () => {
    assert.equal(formatSignedMoney('25.5'), '+25.50');
    assert.equal(formatSignedMoney('0'), '0.00');
    assert.equal(formatSignedMoney('-25.5'), '-25.50');
    assert.equal(formatSignedMoney('-0.001'), '0.00');
});

test('submits the raw value only while the display is untouched', () => {
    assert.equal(submittedMoney('1000.00', '1000.00', '1000.0000000000000000'), '1000.0000000000000000');
    assert.equal(submittedMoney('1250.00', '1000.00', '1000.0000000000000000'), '1250.00');
    assert.equal(submittedMoney('', '', '2500.0000000000000000'), '2500.0000000000000000');
});
