<?php

use App\Domain\Grid\GridDerivation;
use App\Domain\Support\Decimal;

it('converts a percentage spacing to a geometric fraction', function (string $percent, string $expected) {
    expect(GridDerivation::percentToFraction($percent)->toString())->toBe($expected);
})->with([
    ['0.4', '0.004'],
    ['1', '0.01'],
    ['0.05', '0.0005'],
    ['2.5', '0.025'],
]);

it('derives order quantity from capital, utilization, levels and center price', function () {
    $quantity = GridDerivation::orderQuantity('1000', '0.8', 6, '50000');

    expect($quantity)->toBeInstanceOf(Decimal::class)
        ->and($quantity->toString())->toBe('0.0026666666666667');
});

it('keeps derived values as exact decimal strings', function () {
    $quantity = GridDerivation::orderQuantity('1000', '0.5', 4, '250');

    expect($quantity->toString())->toBe('0.5');
});

it('rejects float input', function () {
    expect(fn () => GridDerivation::percentToFraction(0.4))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => GridDerivation::orderQuantity(1000.0, '0.8', 6, '50000'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => GridDerivation::orderQuantity('1000', '0.8', 6, 50000.0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-positive level count or center price', function () {
    expect(fn () => GridDerivation::orderQuantity('1000', '0.8', 0, '50000'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => GridDerivation::orderQuantity('1000', '0.8', 6, '0'))
        ->toThrow(InvalidArgumentException::class);
});
