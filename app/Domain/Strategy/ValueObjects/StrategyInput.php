<?php

namespace App\Domain\Strategy\ValueObjects;

final readonly class StrategyInput
{
    public function __construct(
        public MarketSnapshot $market,
        public BotSnapshot $bot,
        public StrategyConfiguration $config,
        public StrategyState $state,
    ) {
    }

    public function withMarket(MarketSnapshot $market): self
    {
        return new self($market, $this->bot, $this->config, $this->state);
    }

    public function withBot(BotSnapshot $bot): self
    {
        return new self($this->market, $bot, $this->config, $this->state);
    }

    public function withState(StrategyState $state): self
    {
        return new self($this->market, $this->bot, $this->config, $state);
    }
}
