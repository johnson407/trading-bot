<?php

namespace App\Strategies;

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;
use App\Strategies\Concerns\GeneratesGridLevels;

class TrailingGridStrategy implements StrategyInterface
{
    use GeneratesGridLevels;

    public const KEY = 'trailing-grid';

    public function key(): string
    {
        return self::KEY;
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        if (! $input->state->isNew()) {
            return StrategyDecision::idle($input->state);
        }

        $center = $this->initialCenter($input);
        $levels = $this->levelsFor($input, $center);
        $state = $input->state->merge([
            'initialized' => true,
            'center_price' => $center->toString(),
            'spacing_type' => $this->spacingType($input)->value,
            'spacing_value' => $this->spacingValue($input)->toString(),
            'levels_per_side' => $this->levelsPerSide($input),
            'trailing_distance' => $this->trailingDistance($input)->toString(),
            'recenter_count' => 0,
            'last_fill_side' => null,
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

        $center = Decimal::of($input->state->get('center_price'));
        $distance = $this->trailingDistance($input);

        if ($input->market->price->sub($center)->abs()->lessThan($distance)) {
            return new StrategyDecision(
                state: $input->state,
                levels: $this->getGridLevels($input),
            );
        }

        $newCenter = $input->market->price;
        $levels = $this->levelsFor($input, $newCenter);
        $levelPrices = array_map(fn (GridLevelPlan $level) => $level->price->toString(), $levels);

        $cancels = [];
        $remainingOpen = [];

        foreach ($input->bot->openOrders as $order) {
            if (in_array($order->price->toString(), $levelPrices, true)) {
                $remainingOpen[] = $order;
            } else {
                $cancels[] = $order->clientOrderId;
            }
        }

        $state = $input->state->merge([
            'center_price' => $newCenter->toString(),
            'recenter_count' => ((int) $input->state->get('recenter_count', 0)) + 1,
            'last_recenter_price' => $newCenter->toString(),
        ]);

        $input = $input->withState($state)->withBot(
            $input->bot->withOpenOrders($remainingOpen),
        );

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
        $next = $this->adjacentLevel($levels, $fill->gridLevelSequence, $fill->side);
        $state = $this->applyFillToLevelState($base, $fill, $next);

        return new StrategyDecision(
            state: $state,
            levels: $levels,
            orders: $next === null ? [] : [
                OrderIntent::limit($next->side, $next->price, $fill->quantity, $next->sequence, 'strategy.opposite'),
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
            return $this->levelsFor($input, $this->initialCenter($input));
        }

        return $this->levelsFor($input, Decimal::of($input->state->get('center_price')));
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
    private function levelsFor(StrategyInput $input, Decimal $center): array
    {
        return $this->buildLevels(
            $this->key(),
            $center,
            $this->spacingType($input),
            $this->spacingValue($input),
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

    private function initialCenter(StrategyInput $input): Decimal
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

    private function trailingDistance(StrategyInput $input): Decimal
    {
        $distance = $input->config->decimal('trailing_distance');

        if (! $distance->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'trailing_distance must be positive');
        }

        return $distance;
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

    private function spacingType(StrategyInput $input): GridSpacingType
    {
        $value = $input->config->get('spacing_type', GridSpacingType::Arithmetic->value);

        if (! is_string($value) || GridSpacingType::tryFrom($value) === null) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'spacing_type must be arithmetic or geometric');
        }

        return GridSpacingType::from($value);
    }

    private function spacingValue(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('spacing_value');

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'spacing_value must be positive');
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
}
