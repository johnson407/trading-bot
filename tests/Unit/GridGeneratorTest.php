<?php

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Exceptions\InvalidGridException;
use App\Domain\Grid\GridGenerator;
use App\Domain\Order\Enums\OrderSide;

it('generates an arithmetic ladder around the center', function () {
    $levels = app(GridGenerator::class)->generate('100', GridSpacingType::Arithmetic, '10', 2);

    expect(array_map(fn ($level) => $level->price->toString(), $levels))
        ->toBe(['80', '90', '110', '120'])
        ->and(array_map(fn ($level) => $level->sequence, $levels))->toBe([1, 2, 3, 4])
        ->and($levels[0]->side)->toBe(OrderSide::Buy)
        ->and($levels[3]->side)->toBe(OrderSide::Sell);
});

it('generates a geometric ladder around the center', function () {
    $levels = app(GridGenerator::class)->generate('100', GridSpacingType::Geometric, '0.1', 2);

    expect(array_map(fn ($level) => $level->price->toString(), $levels))
        ->toBe(['82.6446280991735537', '90.9090909090909091', '110', '121']);
});

it('uses strategy-provided spacing as an arithmetic ladder', function () {
    $levels = app(GridGenerator::class)->generate('100', GridSpacingType::Dynamic, '7.5', 1);

    expect(array_map(fn ($level) => $level->price->toString(), $levels))
        ->toBe(['92.5', '107.5']);
});

it('rejects invalid grid parameters', function (string $center, GridSpacingType $type, string $spacing, int $perSide) {
    expect(fn () => app(GridGenerator::class)->generate($center, $type, $spacing, $perSide))
        ->toThrow(InvalidGridException::class);
})->with([
    'zero center' => ['0', GridSpacingType::Arithmetic, '10', 1],
    'negative center' => ['-100', GridSpacingType::Arithmetic, '10', 1],
    'zero spacing' => ['100', GridSpacingType::Arithmetic, '0', 1],
    'negative spacing' => ['100', GridSpacingType::Arithmetic, '-1', 1],
    'zero levels' => ['100', GridSpacingType::Arithmetic, '10', 0],
    'geometric spacing of one' => ['100', GridSpacingType::Geometric, '1', 1],
    'level below zero' => ['5', GridSpacingType::Arithmetic, '10', 1],
]);

it('rejects duplicate generated prices', function () {
    expect(fn () => app(GridGenerator::class)->generate('1', GridSpacingType::Arithmetic, '0.00000000000000001', 1))
        ->toThrow(InvalidGridException::class, 'duplicate');
});
