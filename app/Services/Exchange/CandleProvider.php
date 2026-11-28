<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\ValueObjects\Candle;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CandleProvider
{
    public function __construct(private readonly ExchangeClientFactory $clients) {}

    /**
     * Fetch public historical candles for a window, using a short cache and
     * single-flight lock so a page refresh cannot stampede the exchange.
     *
     * @return array{available: bool, points: list<array{t: int, price: string}>, error: ?string}
     */
    public function get(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        $key = $this->key($exchange, $symbol, $timeframe, $limit);
        $cached = $this->cached($key);

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock($key.':lock', 15);

        try {
            $lock->block(1);
        } catch (LockTimeoutException) {
            return $this->unavailable('Price history is loading.');
        }

        try {
            $cached = $this->cached($key);

            if ($cached !== null) {
                return $cached;
            }

            try {
                $candles = $this->clients
                    ->forExchange(
                        $exchange,
                        max(1, (int) config('trading.grid_activity.max_attempts', 1)),
                        max(1, (int) config('trading.grid_activity.request_timeout_ms', 5000)),
                    )
                    ->candles($symbol, $timeframe, $limit);
            } catch (Throwable $exception) {
                Log::debug('Grid activity price history unavailable.', [
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'error' => $exception->getMessage(),
                ]);

                $result = $this->unavailable('Price history is unavailable.');
                Cache::put(
                    $key,
                    $result,
                    max(1, (int) config('trading.grid_activity.failure_cache_seconds', 30)),
                );

                return $result;
            }

            $points = array_map(
                fn (Candle $candle): array => [
                    't' => $candle->openTime->getTimestamp() * 1000,
                    'price' => $candle->close->toString(),
                ],
                array_values($candles),
            );

            $available = $points !== [];

            $result = [
                'available' => $available,
                'points' => $points,
                'error' => $available ? null : 'No price data for this window.',
            ];

            Cache::put($key, $result, max(1, (int) config('trading.grid_activity.cache_seconds', 60)));

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function key(string $exchange, string $symbol, string $timeframe, int $limit): string
    {
        return "grid-activity:candles:{$exchange}:{$symbol}:{$timeframe}:{$limit}";
    }

    /**
     * @return array{available: bool, points: list<array{t: int, price: string}>, error: ?string}|null
     */
    private function cached(string $key): ?array
    {
        $cached = Cache::get($key);

        if (! is_array($cached)
            || ! array_key_exists('available', $cached)
            || ! isset($cached['points'])
            || ! is_array($cached['points'])) {
            return null;
        }

        return $cached;
    }

    /**
     * @return array{available: false, points: array{}, error: string}
     */
    private function unavailable(string $message): array
    {
        return ['available' => false, 'points' => [], 'error' => $message];
    }
}
