<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Models\Bot;

class ExchangeClientFactory
{
    /** @var array<string, ExchangeClient> */
    private array $liveClients = [];

    /** @var array<string, ExchangeClient> */
    private array $paperClients = [];

    /**
     * @param  array<string, ExchangeClient>  $overrides  Clients keyed by exchange id, used by tests.
     */
    public function __construct(
        private readonly ?ExchangeClient $paper = null,
        private readonly ?ExchangeClient $live = null,
        private readonly array $overrides = [],
    ) {}

    /**
     * Public-data client for an exchange, used before a bot exists.
     *
     * A bounded client (shorter timeout, fewer attempts) is used for
     * request-time public data such as candles and must not reuse the
     * shared default client.
     */
    public function forExchange(string $exchange, ?int $maxAttempts = null, ?int $timeoutMs = null): ExchangeClient
    {
        if (isset($this->overrides[$exchange])) {
            return $this->overrides[$exchange];
        }

        if ($this->paper !== null) {
            return $this->paper;
        }

        $key = $exchange.'|'.($maxAttempts ?? 'default').'|'.($timeoutMs ?? 'default');

        return $this->paperClients[$key] ??= new PaperExchangeClient(
            new CcxtExchangeClient(
                exchange: $exchange,
                maxAttempts: $maxAttempts,
                timeoutMs: $timeoutMs,
            ),
        );
    }

    public function forBot(Bot $bot): ExchangeClient
    {
        if (isset($this->overrides[$bot->exchange])) {
            return $this->overrides[$bot->exchange];
        }

        if ($bot->isLive()) {
            if ($this->live !== null) {
                return $this->live;
            }

            // A client caches the credential it authenticated with, so the
            // cache key must separate bots that share an exchange but not keys.
            $key = $bot->exchange.'|'.($bot->exchange_credential_id ?? 'none');

            return $this->liveClients[$key] ??= new CcxtExchangeClient(exchange: $bot->exchange);
        }

        if ($this->paper !== null) {
            return $this->paper;
        }

        return $this->paperClients[$bot->exchange] ??= new PaperExchangeClient(
            new CcxtExchangeClient(exchange: $bot->exchange),
        );
    }
}
