<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Models\Bot;

class ExchangeClientFactory
{
    /**
     * @param  array<string, ExchangeClient>  $overrides  Clients keyed by exchange id, used by tests.
     */
    public function __construct(
        private readonly ?ExchangeClient $paper = null,
        private readonly ?ExchangeClient $live = null,
        private readonly array $overrides = [],
    ) {}

    public function forBot(Bot $bot): ExchangeClient
    {
        if (isset($this->overrides[$bot->exchange])) {
            return $this->overrides[$bot->exchange];
        }

        if ($bot->isLive()) {
            return $this->live ?? new CcxtExchangeClient(exchange: $bot->exchange);
        }

        if ($this->paper !== null) {
            return $this->paper;
        }

        return new PaperExchangeClient(new CcxtExchangeClient(exchange: $bot->exchange));
    }
}
