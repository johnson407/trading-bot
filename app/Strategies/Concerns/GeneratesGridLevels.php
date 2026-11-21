<?php

namespace App\Strategies\Concerns;

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Domain\Support\Decimal;

trait GeneratesGridLevels
{
    /**
     * @return list<GridLevelPlan>
     */
    protected function buildLevels(
        string $strategyKey,
        Decimal $center,
        GridSpacingType $type,
        Decimal $spacing,
        int $perSide,
    ): array {
        if ($perSide < 1) {
            throw InvalidStrategyConfigurationException::forStrategy($strategyKey, 'levels_per_side must be at least 1');
        }

        if (! $spacing->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($strategyKey, 'spacing_value must be positive');
        }

        if ($type === GridSpacingType::Geometric && $spacing->greaterThanOrEqual('1')) {
            throw InvalidStrategyConfigurationException::forStrategy($strategyKey, 'geometric spacing must be less than 1');
        }

        $levels = [];

        for ($i = 1; $i <= $perSide; $i++) {
            $price = $type === GridSpacingType::Arithmetic
                ? $center->sub($spacing->mul($i))
                : $this->geometricPrice($center, $spacing, $i, false);

            if (! $price->isPositive()) {
                throw InvalidStrategyConfigurationException::forStrategy(
                    $strategyKey,
                    'generated a non-positive level price; reduce spacing or level count',
                );
            }

            $levels[] = GridLevelPlan::make(0, $price, OrderSide::Buy);
        }

        for ($i = 1; $i <= $perSide; $i++) {
            $price = $type === GridSpacingType::Arithmetic
                ? $center->add($spacing->mul($i))
                : $this->geometricPrice($center, $spacing, $i, true);

            $levels[] = GridLevelPlan::make(0, $price, OrderSide::Sell);
        }

        usort($levels, fn (GridLevelPlan $a, GridLevelPlan $b) => $a->price->compare($b->price));

        $assigned = [];
        $seen = [];

        foreach ($levels as $index => $level) {
            $key = $level->price->toString();

            if (isset($seen[$key])) {
                throw InvalidStrategyConfigurationException::forStrategy(
                    $strategyKey,
                    "duplicate grid level price [{$key}]",
                );
            }

            $seen[$key] = true;
            $assigned[] = new GridLevelPlan(
                $index + 1,
                $level->price,
                $level->price->lessThan($center) ? OrderSide::Buy : OrderSide::Sell,
            );
        }

        return $assigned;
    }

    protected function applyFillToLevelState(StrategyState $state, FillEvent $fill, ?GridLevelPlan $next): StrategyState
    {
        $disarmed = $state->get('disarmed_levels', []);
        $disarmed = is_array($disarmed) ? $disarmed : [];
        $price = $fill->price->toString();

        foreach ($disarmed as $key => $entry) {
            if (is_array($entry) && ($entry['pair'] ?? null) === $price) {
                unset($disarmed[$key]);
            }
        }

        if ($fill->gridLevelSequence !== null) {
            $disarmed[$price] = [
                'side' => $fill->side->value,
                'pair' => $next?->price->toString(),
            ];
        }

        return $state->merge(['disarmed_levels' => $disarmed]);
    }

    protected function levelIsArmed(StrategyState $state, GridLevelPlan $level): bool
    {
        $disarmed = $state->get('disarmed_levels', []);

        return ! is_array($disarmed) || ! array_key_exists($level->price->toString(), $disarmed);
    }

    private function geometricPrice(Decimal $center, Decimal $spacing, int $step, bool $above): Decimal
    {
        $factor = Decimal::of('1')->add($spacing);
        $multiplier = Decimal::of('1');

        for ($i = 0; $i < $step; $i++) {
            $multiplier = $multiplier->mul($factor);
        }

        return $above ? $center->mul($multiplier) : $center->div($multiplier);
    }
}
