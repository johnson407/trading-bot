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

class FakeStrategy implements StrategyInterface
{
    public function key(): string
    {
        return 'fake';
    }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        if (! $input->state->isNew()) {
            return StrategyDecision::idle($input->state);
        }

        return new StrategyDecision(
            state: $input->state->merge(['initialized' => true, 'ticks' => 0, 'fills' => 0, 'cancels' => 0]),
            levels: $this->getGridLevels($input),
        );
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        $state = $input->state->merge(['ticks' => ((int) $input->state->get('ticks', 0)) + 1]);

        return new StrategyDecision(
            state: $state,
            levels: $this->getGridLevels($input),
            orders: [
                OrderIntent::limit(OrderSide::Buy, '100', '1', 1),
            ],
        );
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        $state = $input->state->merge(['fills' => ((int) $input->state->get('fills', 0)) + 1]);

        return new StrategyDecision(
            state: $state,
            orders: [
                OrderIntent::limit($fill->side->opposite(), '101', $fill->quantity, $fill->gridLevelSequence),
            ],
        );
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        $state = $input->state->merge(['cancels' => ((int) $input->state->get('cancels', 0)) + 1]);

        return StrategyDecision::idle($state);
    }

    public function shouldStop(StrategyInput $input): bool
    {
        return $input->market->price->greaterThanOrEqual('999');
    }

    public function getGridLevels(StrategyInput $input): array
    {
        return [
            GridLevelPlan::make(1, '100', OrderSide::Buy),
        ];
    }
}
