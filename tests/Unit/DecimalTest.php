<?php

use App\Domain\Support\Decimal;

it('adds and subtracts exact decimal strings', function () {
    expect((string) Decimal::of('0.1')->add('0.2'))->toBe('0.3')
        ->and((string) Decimal::of('1.5')->sub('2.25'))->toBe('-0.75')
        ->and((string) Decimal::of('1000')->add('0.0000000000000001'))->toBe('1000.0000000000000001');
});

it('multiplies and divides without binary floating point', function () {
    expect((string) Decimal::of('2.5')->mul('4'))->toBe('10')
        ->and((string) Decimal::of('0.1')->mul('0.1'))->toBe('0.01')
        ->and((string) Decimal::of('10')->div('4'))->toBe('2.5')
        ->and((string) Decimal::of('1')->div('3'))->toBe('0.3333333333333333');
});

it('compares decimals at full scale', function () {
    expect(Decimal::of('1.0000000000000001')->greaterThan('1'))->toBeTrue()
        ->and(Decimal::of('-2')->lessThan('-1.999'))->toBeTrue()
        ->and(Decimal::of('1.0')->equals('1.000'))->toBeTrue()
        ->and(Decimal::of('1')->compare('2'))->toBe(-1);
});

it('rounds half up, down, and up', function () {
    expect((string) Decimal::of('1.2345')->round(2))->toBe('1.23')
        ->and((string) Decimal::of('1.235')->round(2))->toBe('1.24')
        ->and((string) Decimal::of('-1.235')->round(2))->toBe('-1.24')
        ->and((string) Decimal::of('1.239')->round(2, Decimal::ROUND_DOWN))->toBe('1.23')
        ->and((string) Decimal::of('1.231')->round(2, Decimal::ROUND_UP))->toBe('1.24')
        ->and((string) Decimal::of('0.999')->round(2))->toBe('1')
        ->and((string) Decimal::of('9.999')->round(2))->toBe('10');
});

it('formats fixed-scale strings for persistence', function () {
    expect(Decimal::of('1.5')->toFixed(4))->toBe('1.5000')
        ->and(Decimal::of('-0.25')->toFixed(2))->toBe('-0.25')
        ->and(Decimal::of('0')->toFixed(2))->toBe('0.00');
});

it('rejects floating-point and malformed input', function () {
    expect(fn () => Decimal::of(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::of('1e-8'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::of('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::of('1')->div('0'))->toThrow(InvalidArgumentException::class);
});
