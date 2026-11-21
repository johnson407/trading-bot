<?php

use App\Domain\Support\Number;

it('passes decimal strings and integers through', function () {
    expect(Number::decimalString('100.5'))->toBe('100.5')
        ->and(Number::decimalString(' 42 '))->toBe('42')
        ->and(Number::decimalString(100))->toBe('100');
});

it('converts floats without scientific notation', function () {
    expect(Number::decimalString(1.0E-5))->toBe('0.00001')
        ->and(Number::decimalString(0.1))->toBe('0.1')
        ->and(Number::decimalString(1234.5))->toBe('1234.5')
        ->and(Number::decimalString(-2.5E-7))->toBe('-0.00000025');
});

it('expands exponent strings', function () {
    expect(Number::decimalString('1.0E-5'))->toBe('0.00001')
        ->and(Number::decimalString('2.5e3'))->toBe('2500')
        ->and(Number::decimalString('-1.25E+2'))->toBe('-125');
});

it('rejects values that cannot become decimals', function () {
    expect(fn () => Number::decimalString(null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Number::decimalString(true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Number::decimalString([1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Number::decimalString(INF))->toThrow(InvalidArgumentException::class);
});
