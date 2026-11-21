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

class MartingaleGridStrategy implements StrategyInterface
{
    use GeneratesGridLevels;

    public const KEY = 'martingale-grid';

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
        $levels = $this->levelsFor($input, $center);
        $state = $input->state->merge([
            'initialized' => true,
            'center_price' => $center->toString(),
            'spacing_type' => $this->spacingType($input)->value,
            'spacing_value' => $this->spacingValue($input)->toString(),
            'levels_per_side' => $this->levelsPerSide($input),
            'current_quantity' => $this->baseQuantity($input)->toString(),
            'increase_count' => 0,
            'cap_reached' => false,
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

        $levels = $this->getGridLevels($input);

        return new StrategyDecision(
            state: $input->state,
            levels: $levels,
            orders: $this->orderIntents($input, $levels),
        );
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        $current = Decimal::of($input->state->get('current_quantity', $this->baseQuantity($input)->toString()));
        $increaseCount = (int) $input->state->get('increase_count', 0);
        $maxIncreases = $this->maxIncreases($input);
        $maxQuantity = $this->maxQuantity($input);
        $capReached = (bool) $input->state->get('cap_reached', false);
        $events = [];

        $next = $current;

        if (! $capReached && $increaseCount < $maxIncreases) {
            $candidate = $current->mul($this->sizeMultiplier($input));
            $next = $candidate->greaterThan($maxQuantity) ? $maxQuantity : $candidate;
            $increaseCount++;

            if ($next->greaterThanOrEqual($maxQuantity) || $increaseCount >= $maxIncreases) {
                $capReached = true;
                $events[] = [
                    'type' => 'martingale.cap_reached',
                    'message' => 'Martingale size cap reached',
                    'context' => [
                        'quantity' => $next->toString(),
                        'increase_count' => $increaseCount,
                        'max_order_quantity' => $maxQuantity->toString(),
                        'max_increases' => $maxIncreases,
                    ],
                ];
            }
        }

        $state = $input->state->merge([
            'last_fill_side' => $fill->side->value,
            'fill_count' => ((int) $input->state->get('fill_count', 0)) + 1,
            'current_quantity' => $next->toString(),
            'increase_count' => $increaseCount,
            'cap_reached' => $capReached,
        ]);

        $levels = $this->getGridLevels($input->withState($state));
        $adjacent = $this->adjacentLevel($levels, $fill->gridLevelSequence, $fill->side);
        $state = $this->applyFillToLevelState($state, $fill, $adjacent);

        $orders = $adjacent === null
            ? []
            : [OrderIntent::limit(
                $adjacent->side,
                $adjacent->price,
                $next,
                $adjacent->sequence,
                'strategy.opposite',
            )];

        return new StrategyDecision(
            state: $state,
            levels: $levels,
            orders: $orders,
            events: $events,
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
            return $this->levelsFor($input, $this->centerPrice($input));
        }

        return $this->levelsFor($input, Decimal::of($input->state->get('center_price')));
    }

    /** @return list<OrderIntent> */
    private function orderIntents(StrategyInput $input, array $levels): array
    {
        $quantity = Decimal::of($input->state->get('current_quantity', $this->baseQuantity($input)->toString()));
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

    private function baseQuantity(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('base_order_quantity', $input->config->get('order_quantity'));

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'base_order_quantity must be positive');
        }

        return $value;
    }

    private function sizeMultiplier(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('size_multiplier');

        if (! $value->greaterThan('1')) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'size_multiplier must be greater than 1');
        }

        return $value;
    }

    private function maxQuantity(StrategyInput $input): Decimal
    {
        $value = $input->config->decimal('max_order_quantity');

        if (! $value->isPositive()) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'max_order_quantity must be positive');
        }

        if ($value->lessThan($this->baseQuantity($input))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'max_order_quantity must be at least base_order_quantity');
        }

        return $value;
    }

    private function maxIncreases(StrategyInput $input): int
    {
        $value = $input->config->get('max_increases', 0);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'max_increases must be an integer');
        }

        $value = (int) $value;

        if ($value < 0) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key(), 'max_increases must not be negative');
        }

        return $value;
    }
}
