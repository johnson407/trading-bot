<?php

namespace App\Domain\Grid;

use App\Domain\Support\Decimal;
use InvalidArgumentException;

/**
 * Decimal-only helpers for turning quick-setup choices into bot parameters.
 *
 * Percentages are accepted in percent form (`0.4` means 0.4%) and converted
 * to the geometric fraction the strategy expects (`0.004`).
 */
final class GridDerivation
{
    public static function percentToFraction(mixed $percent): Decimal
    {
        $fraction = Decimal::of($percent)->div('100');

        if (! $fraction->isPositive()) {
            throw new InvalidArgumentException('Spacing percent must be positive.');
        }

        return $fraction;
    }

    public static function orderQuantity(
        mixed $capital,
        mixed $utilization,
        int $levelsPerSide,
        mixed $centerPrice,
    ): Decimal {
        if ($levelsPerSide < 1) {
            throw new InvalidArgumentException('Levels per side must be at least 1.');
        }

        $center = Decimal::of($centerPrice);

        if (! $center->isPositive()) {
            throw new InvalidArgumentException('Center price must be positive.');
        }

        return Decimal::of($capital)
            ->mul($utilization)
            ->div((string) $levelsPerSide)
            ->div($center);
    }
}
