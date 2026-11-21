<?php

namespace App\Domain\Grid;

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Exceptions\InvalidGridException;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Support\Decimal;

class GridGenerator
{
    /**
     * Build a symmetric ladder around the center price.
     *
     * @return list<GridLevelPlan> sorted by price ascending, sequences 1..2N
     */
    public function generate(
        Decimal|string $centerPrice,
        GridSpacingType $spacingType,
        Decimal|string $spacingValue,
        int $levelsPerSide,
    ): array {
        $center = $this->decimal($centerPrice);
        $spacing = $this->decimal($spacingValue);

        if (! $center->isPositive()) {
            throw InvalidGridException::because('center price must be positive');
        }

        if (! $spacing->isPositive()) {
            throw InvalidGridException::because('spacing must be positive');
        }

        if ($levelsPerSide < 1) {
            throw InvalidGridException::because('levels per side must be at least 1');
        }

        if ($spacingType === GridSpacingType::Geometric && $spacing->greaterThanOrEqual('1')) {
            throw InvalidGridException::because('geometric spacing must be less than 1');
        }

        $prices = [];

        for ($index = 1; $index <= $levelsPerSide; $index++) {
            if ($spacingType === GridSpacingType::Geometric) {
                $below = $center->div(Decimal::of('1')->add($spacing)->pow($index));
                $above = $center->mul(Decimal::of('1')->add($spacing)->pow($index));
            } else {
                $below = $center->sub($spacing->mul((string) $index));
                $above = $center->add($spacing->mul((string) $index));
            }

            if (! $below->isPositive() || ! $above->isPositive()) {
                throw InvalidGridException::because('generated levels must have positive prices');
            }

            $prices[] = $below;
            $prices[] = $above;
        }

        usort($prices, fn (Decimal $a, Decimal $b) => $a->compare($b));

        $levels = [];
        $seen = [];

        foreach ($prices as $index => $price) {
            $key = $price->toFixed(16);

            if (isset($seen[$key])) {
                throw InvalidGridException::because("duplicate level price [{$key}]");
            }

            $seen[$key] = true;
            $levels[] = GridLevelPlan::make(
                $index + 1,
                $price,
                $price->lessThan($center) ? OrderSide::Buy : OrderSide::Sell,
            );
        }

        return $levels;
    }

    private function decimal(Decimal|string $value): Decimal
    {
        try {
            return Decimal::of($value);
        } catch (\InvalidArgumentException $exception) {
            throw InvalidGridException::because($exception->getMessage());
        }
    }
}
