<?php

namespace App\Domain\Strategy\ValueObjects;

final readonly class StrategyDecision
{
    /**
     * @param  list<GridLevelPlan>  $levels
     * @param  list<OrderIntent>  $orders
     * @param  list<string>  $cancelClientOrderIds
     * @param  list<array{type: string, message: string, context?: array<string, mixed>}>  $events
     */
    public function __construct(
        public StrategyState $state,
        public array $levels = [],
        public array $orders = [],
        public bool $stop = false,
        public array $events = [],
        public array $cancelClientOrderIds = [],
    ) {
    }

    public static function idle(StrategyState $state): self
    {
        return new self(state: $state);
    }

    /** @param list<GridLevelPlan> $levels */
    public function withLevels(array $levels): self
    {
        return new self($this->state, $levels, $this->orders, $this->stop, $this->events, $this->cancelClientOrderIds);
    }

    /** @param list<OrderIntent> $orders */
    public function withOrders(array $orders): self
    {
        return new self($this->state, $this->levels, $orders, $this->stop, $this->events, $this->cancelClientOrderIds);
    }

    public function withState(StrategyState $state): self
    {
        return new self($state, $this->levels, $this->orders, $this->stop, $this->events, $this->cancelClientOrderIds);
    }

    public function withStop(bool $stop): self
    {
        return new self($this->state, $this->levels, $this->orders, $stop, $this->events, $this->cancelClientOrderIds);
    }

    /** @param list<string> $clientOrderIds */
    public function withCancels(array $clientOrderIds): self
    {
        return new self($this->state, $this->levels, $this->orders, $this->stop, $this->events, $clientOrderIds);
    }
}
