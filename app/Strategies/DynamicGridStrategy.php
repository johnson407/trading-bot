<?php

namespace App\Strategies;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;
use App\Strategies\Concerns\GeneratesGridLevels;
use DateTimeImmutable;

class DynamicGridStrategy implements StrategyInterface
{
    use GeneratesGridLevels;

    public const KEY = 'dynamic-grid';

    public function key(): string
    {
        return self::KEY;
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        if (! $input->state->isNew()) {
            return StrategyDecision::idle($input->state);
        }

        $center = $this->centerPrice($input);
        $spacing = $this->calculateSpacing($input, $center);
        $levels = $this->levelsFor($input, $center, $spacing);
        $state = $input->state->merge([
            'initialized' => true,
            'center_price' => $center->toString(),
            'spacing_value' => $spacing->toString(),
            'levels_per_side' => $this->levelsPerSide($input),
            'last_recalculated_at' => $this->timestamp($input)->format(DATE_ATOM),
            'recalculation_count' => 0,
            'fill_count' => 0,
        ]);

        return new StrategyDecision(state: $state, levels: $levels);
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        if ($input->state->isNew()) {
            $initialized = $this->initialize($input);
            $input = $input->withState($initialized->state);

            return $initialized->withOrders($this->orderIntents($input, $initialized->levels));
        }

        $last = new DateTimeImmutable((string) $input->state->get('last_recalculated_at'));
        $interval = $this->recalculationInterval($input);

        if ($this->timestamp($input)->getTimestamp() - $last->getTimestamp() < $interval) {
            return new StrategyDecision(
                state: $input->state,
                levels: $this->getGridLevels($input),
            );
        }

        $center = Decimal::of($input->state->get('center_price'));
        $spacing = $this->calculateSpacing($input, $center);
        $levels = $this->levelsFor($input, $center, $spacing);
        $prices = array_map(fn (GridLevelPlan $level) => $level->price->toString(), $levels);

        $cancels = [];
        $remaining = [];

        foreach ($input->bot->openOrders as $order) {
            if (in_array($order->price->toString(), $prices, true)) {
                $remaining[] = $order;
            } else {
                $cancels[] = $order->clientOrderId;
            }
        }

        $state = $input->state->merge([
            'spacing_value' => $spacing->toString(),
            'last_recalculated_at' => $this->timestamp($input)->format(DATE_ATOM),
            'recalculation_count' => ((int) $input->state->get('recalculation_count', 0)) + 1,
        ]);

        $input = $input->withState($state)->withBot($input->bot->withOpenOrders($remaining));

        return new StrategyDecision(
            state: $state,
            levels: $levels,
            orders: $this->orderIntents($input, $levels),
            cancelClientOrderIds: $cancels,
        );
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        $base = $input->state->merge([
            'last_fill_side' => $fill->side->value,
            'fill_count' => ((int) $input->state->get('fill_count', 0)) + 1,
        ]);

        $levels = $this->getGridLevels($input->withState($base));
        $adjacent = $this->adjacentLevel($levels, $fill->gridLevelSequence, $fill->side);
        $state = $this->applyFillToLevelState($base, $fill, $adjacent);

        return new StrategyDecision(
            state: $state,
            levels: $levels,
            orders: $adjacent === null ? [] : [
                OrderIntent::limit($adjacent->side, $adjacent->price, $fill->quantity, $adjacent->sequence, 'strategy.opposite'),
            ],
        );
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        return StrategyDecision::idle($input->state->merge([
            'last_canceled_client_order_id' => $cancel->clientOrderId,
        ]));
    }

    public function shouldStop(StrategyInput $input): bool
    {
        return false;
    }

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array
    {
        if ($input->state->isNew()) {
            $center = $this->centerPrice($input);

            return $this->levelsFor($input, $center, $this->calculateSpacing($input, $center));
        }

        return $this->levelsFor(
            $input,
            Decimal::of($input->state->get('center_price')),
            Decimal::of($input->state->get('spacing_value')),
        );
    }

    /** @return list<OrderIntent> */
    private function orderIntents(StrategyInput $input, array $levels): array
    {
        $quantity = $this->orderQuantity($input);
        $orders = [];

        foreach ($levels as $level) {
            if (! $this->levelIsArmed($input->state, $level)) {
                continue;
            }

            if ($input->bot->hasOpenOrderAt($level->price->toString())) {
                continue;
            }

            $orders[] = OrderIntent::limit($level->side, $level->price, $quantity, $level->sequence);
        }

        return $orders;
    }

    /** @return list<GridLevelPlan> */
    private function levelsFor(StrategyInput $input, Decimal $center, Decimal $spacing): array
    {
        return $this->buildLevels(
            $this->key(),
            $center,
            \App\Domain\Grid\Enums\GridSpacingType::Arithmetic,
            $spacing,
            $this->levelsPerSide($input),
        );
    }

    /** @param list<GridLevelPlan> $levels */
    private function adjacentLevel(array $levels, ?int $sequence, OrderSide $side): ?GridLevelPlan
    {
        if ($sequence === null) {
            return null;
        }

        $target = $side === OrderSide::Buy ? $sequence + 1 : $sequence - 1;

        foreach ($levels as $level) {
            if ($level->sequence === $target) {
                return $level;
            }
        }

        return null;
    }

    private function calculateSpacing(StrategyInput $input, Decimal $center): Decimal
    {
        $prices = array_map(fn (string $price) => Decimal::of($price), $input->market->recentPrices);
        $prices[] = $input->market->price;

        if (count($prices) < 2) {
            return $this->initialSpacing($input);
        }

        $min = $prices[0];
        $max = $prices[0];

        foreach ($prices as $price) {
            $min = $min->lessThan($price) ? $min : $price;
            $max = $max->greaterThan($price) ? $max : $price;
        }

        $midpoint = $min->add($max)->div('2');

        if (! $midpoint->isPositive()) {
            return $this->clamp($this->initialSpacing($input), $input);
        }

        $volatility = $max->sub($min)->div($midpoint);
        $spacing = $center->mul($volatility)->mul($this->volatilityMultiplier($input));

        return $this->clamp($spacing, $input);
    }

    private function clamp(Decimal $spacing, StrategyInput $input): Decimal
    {
        $min = $this->minSpacing($input);
        $max = $this->maxSpacing($input);

        if ($spacing->lessThan($min)) {
            return $min;
        }

        if ($spacing->greaterThan($max)) {
            return $max;
        }

        return $spacing;
    }

    private function centerPrice(StrategyInput $input): Decimal
    {
        if ($input->config->has('center_price')) {
            $center = $input->config->decimal('center_price');
        } else {
            $center = $input->market->price;
        }

        if (! $center->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'center_price must be positive');
        }

        return $center;
    }

    private function levelsPerSide(StrategyInput $input): int
    {
        $value = $input->config->get('levels_per_side', $input->config->get('level_count'));

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'levels_per_side must be an integer');
        }

        $value = (int) $value;

        if ($value < 1) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'levels_per_side must be at least 1');
        }

        return $value;
    }

    private function orderQuantity(StrategyInput $input): Decimal
    {
        $quantity = $input->config->decimal('order_quantity');

        if (! $quantity->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'order_quantity must be positive');
        }

        return $quantity;
    }

    private function minSpacing(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('min_spacing');

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'min_spacing must be positive');
        }

        return $value;
    }

    private function maxSpacing(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('max_spacing');

        if ($value->lessThan($this->minSpacing($input))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'max_spacing must be at least min_spacing');
        }

        return $value;
    }

    private function initialSpacing(StrategyInput $input): Decimal
    {
        if ($input->config->has('initial_spacing')) {
            return $input->config->decimal('initial_spacing');
        }

        return $this->minSpacing($input);
    }

    private function volatilityMultiplier(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('volatility_multiplier', '1');

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'volatility_multiplier must be positive');
        }

        return $value;
    }

    private function recalculationInterval(StrategyInput $input): int
    {
        $value = $input->config->get('recalculation_interval_seconds', 60);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'recalculation_interval_seconds must be an integer');
        }

        $value = (int) $value;

        if ($value < 0) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'recalculation_interval_seconds must not be negative');
        }

        return $value;
    }

    private function timestamp(StrategyInput $input): DateTimeImmutable
    {
        return $input->market->timestamp ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
