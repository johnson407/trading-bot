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
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;
use App\Strategies\Concerns\GeneratesGridLevels;

class SimpleGridStrategy implements StrategyInterface
{
    use GeneratesGridLevels;

    public const KEY = 'simple-grid';

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
        $levels = $this->buildLevels($this->key(), $center, $this->spacingType($input), $this->spacingValue($input), $this->levelsPerSide($input));
        $state = $input->state->merge([
            'initialized' => true,
            'center_price' => $center->toString(),
            'spacing_type' => $this->spacingType($input)->value,
            'spacing_value' => $this->spacingValue($input)->toString(),
            'levels_per_side' => $this->levelsPerSide($input),
            'last_fill_side' => null,
            'fill_count' => 0,
        ]);

        return new StrategyDecision(state: $state, levels: $levels);
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        if ($input->state->isNew()) {
            $initialized = $this->initialize($input);

            return $initialized->withOrders($this->orderIntents($input->withState($initialized->state)));
        }

        $levels = $this->getGridLevels($input);

        return new StrategyDecision(
            state: $input->state,
            levels: $levels,
            orders: $this->orderIntents($input),
        );
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        $levels = $this->getGridLevels($input);
        $next = $this->adjacentLevel($levels, $fill->gridLevelSequence, $fill->side);

        $state = $this->applyFillToLevelState($input->state->merge([
            'last_fill_side' => $fill->side->value,
            'fill_count' => ((int) $input->state->get('fill_count', 0)) + 1,
            'last_fill_price' => $fill->price->toString(),
        ]), $fill, $next);

        $orders = $next === null
            ? []
            : [OrderIntent::limit(
                side: $next->side,
                price: $next->price,
                quantity: $fill->quantity,
                gridLevelSequence: $next->sequence,
                reason: 'strategy.opposite',
            )];

        return new StrategyDecision(state: $state, levels: $levels, orders: $orders);
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
        $center = Decimal::of($input->state->get('center_price', $this->centerPrice($input)->toString()));
        $type = GridSpacingType::from((string) $input->state->get('spacing_type', $this->spacingType($input)->value));
        $spacing = Decimal::of($input->state->get('spacing_value', $this->spacingValue($input)->toString()));

        return $this->buildLevels($this->key(), $center, $type, $spacing, $this->levelsPerSide($input));
    }

    /** @return list<OrderIntent> */
    private function orderIntents(StrategyInput $input): array
    {
        $quantity = $this->orderQuantity($input);
        $orders = [];

        foreach ($this->getGridLevels($input) as $level) {
            if (! $this->levelIsArmed($input->state, $level)) {
                continue;
            }

            if ($input->bot->hasOpenOrderAt($level->price->toString())) {
                continue;
            }

            $orders[] = OrderIntent::limit(
                side: $level->side,
                price: $level->price,
                quantity: $quantity,
                gridLevelSequence: $level->sequence,
            );
        }

        return $orders;
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

    private function centerPrice(StrategyInput $input): Decimal
    {
        $center = $input->config->decimal('center_price');

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

    private function spacingType(StrategyInput $input): GridSpacingType
    {
        $value = $input->config->get('spacing_type', GridSpacingType::Arithmetic->value);

        if (! is_string($value) || GridSpacingType::tryFrom($value) === null) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'spacing_type must be arithmetic or geometric');
        }

        $type = GridSpacingType::from($value);

        if ($type === GridSpacingType::Dynamic) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'simple-grid supports arithmetic or geometric spacing');
        }

        return $type;
    }

    private function spacingValue(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('spacing_value');

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'spacing_value must be positive');
        }

        if ($this->spacingType($input) === GridSpacingType::Geometric && $value->greaterThanOrEqual('1')) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'geometric spacing must be less than 1');
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
