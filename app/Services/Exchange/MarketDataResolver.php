<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\ValueObjects\MarketData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MarketDataResolver
{
    public function __construct(private readonly ExchangeClientFactory $clients) {}

    /**
     * Fetch market data, reusing a short-lived cached copy when one exists.
     * A failed exchange call is reported as "unavailable" rather than thrown,
     * because market data is optional for previews and creation.
     */
    public function resolve(string $exchange, string $symbol): ?MarketData
    {
        $cached = $this->cached($exchange, $symbol);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $market = $this->clients->forExchange($exchange)->market($symbol);
        } catch (Throwable $exception) {
            Log::debug('Market data unavailable for preview.', [
                'exchange' => $exchange,
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $this->prime($exchange, $market);

        return $market;
    }

    /**
     * Read only the cached copy. Requests use this so saving never performs a
     * blocking exchange call; a preview or market-data endpoint primes it.
     */
    public function cached(string $exchange, string $symbol): ?MarketData
    {
        $market = Cache::get($this->key($exchange, $symbol));

        return $market instanceof MarketData ? $market : null;
    }

    public function prime(string $exchange, MarketData $market): void
    {
        $seconds = max(1, (int) config('trading.market_data.cache_seconds', 60));

        Cache::put($this->key($exchange, $market->symbol), $market, $seconds);
    }

    public function key(string $exchange, string $symbol): string
    {
        return "market-data:{$exchange}:{$symbol}";
    }
}
