<?php

namespace Tests\Fixtures;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;

/**
 * The minimal custom strategy documented in docs/strategies.md.
 */
class DocumentedCustomStrategy implements StrategyInterface
{
    public const KEY = 'documented-custom';

    public function key(): string
    {
        return self::KEY;
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        if (! $input->state->isNew()) {
            return StrategyDecision::idle($input->state);
        }

        return StrategyDecision::withLevels(
            $input->state->merge(['initialized' => true]),
            $this->getGridLevels($input),
        );
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        if ($input->state->isNew()) {
            return $this->initialize($input);
        }

        return StrategyDecision::withLevels($input->state, $this->getGridLevels($input));
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        return StrategyDecision::idle($input->state->merge([
            'last_fill' => $fill->exchangeFillId,
        ]));
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        return StrategyDecision::idle($input->state);
    }

    public function shouldStop(StrategyInput $input): bool
    {
        return false;
    }

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array
    {
        return [
            GridLevelPlan::make(1, Decimal::of('99'), OrderSide::Buy),
            GridLevelPlan::make(2, Decimal::of('101'), OrderSide::Sell),
        ];
    }
}
