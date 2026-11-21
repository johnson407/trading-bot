<?php

namespace App\Services\Backtest;

use App\Domain\Backtest\Contracts\CandleProvider;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ExchangeErrorMapper;
use App\Domain\Support\Number;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class CcxtCandleProvider implements CandleProvider
{
    private readonly int $maxAttempts;

    private readonly int $retryDelayMs;

    /** @var array<string, object> */
    private array $clients = [];

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $sleeper;

    public function __construct(
        ?callable $clientFactory = null,
        ?callable $sleeper = null,
        private readonly ?ExchangeErrorMapper $mapper = null,
        ?int $maxAttempts = null,
        ?int $retryDelayMs = null,
    ) {
        $this->clientFactory = $clientFactory;
        $this->sleeper = $sleeper;
        $this->maxAttempts = $maxAttempts ?? (int) config('trading.exchange.max_retries', 3);
        $this->retryDelayMs = $retryDelayMs ?? (int) config('trading.exchange.retry_delay_ms', 250);
    }

    public function fetch(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $start = $start->setTimezone(new DateTimeZone('UTC'));
        $end = $end->setTimezone(new DateTimeZone('UTC'));

        if ($start >= $end) {
            return [];
        }

        $client = $this->client($exchange);
        $limit = max(1, (int) config('backtest.candle_chunk_limit', 1_000));
        $since = $start->getTimestamp() * 1000;
        $endMilliseconds = $end->getTimestamp() * 1000;
        $candles = [];
        $firstRequest = true;

        while ($since < $endMilliseconds) {
            if (! $firstRequest) {
                $this->sleep((int) config('trading.exchange.rate_limit_ms', 1_000));
            }

            $firstRequest = false;

            $batch = $this->withRetry($exchange, 'fetchOHLCV', function () use ($client, $symbol, $timeframe, $since, $limit): mixed {
                return $client->fetch_ohlcv($symbol, $timeframe->value, $since, $limit);
            });

            if (! is_array($batch) || $batch === []) {
                break;
            }

            $lastOpen = $since;
            $added = 0;

            foreach ($batch as $row) {
                if (! is_array($row) || count($row) < 6) {
                    continue;
                }

                $openMilliseconds = (int) $row[0];

                if ($openMilliseconds < $start->getTimestamp() * 1000 || $openMilliseconds >= $endMilliseconds) {
                    $lastOpen = max($lastOpen, $openMilliseconds);

                    continue;
                }

                $candle = Candle::make(
                    $openMilliseconds,
                    Number::decimalString($row[1]),
                    Number::decimalString($row[2]),
                    Number::decimalString($row[3]),
                    Number::decimalString($row[4]),
                    Number::decimalString($row[5]),
                );

                $candles[$candle->openTimeMilliseconds()] = $candle;
                $lastOpen = max($lastOpen, $openMilliseconds);
                $added++;
            }

            $nextSince = $lastOpen + ($timeframe->seconds() * 1000);

            if ($added === 0 && $nextSince <= $since) {
                break;
            }

            if ($nextSince <= $since) {
                break;
            }

            $since = $nextSince;

            if ($since >= $endMilliseconds) {
                break;
            }
        }

        ksort($candles);

        return array_values($candles);
    }

    private function client(string $exchange): object
    {
        if (isset($this->clients[$exchange])) {
            return $this->clients[$exchange];
        }

        $options = [
            'enableRateLimit' => true,
            'rateLimit' => (int) config('trading.exchange.rate_limit_ms', 1_000),
            'timeout' => (int) config('trading.exchange.timeout_ms', 15_000),
        ];

        if ($this->clientFactory !== null) {
            return $this->clients[$exchange] = ($this->clientFactory)($exchange, $options);
        }

        $class = '\\ccxt\\'.strtolower($exchange);

        if (! class_exists($class)) {
            throw ExchangeException::make(
                ExchangeErrorType::Unsupported,
                $exchange,
                'construct',
                "CCXT exchange class [{$class}] is not available.",
            );
        }

        return $this->clients[$exchange] = new $class($options);
    }

    private function withRetry(string $exchange, string $operation, callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $callback();
            } catch (Throwable $exception) {
                $mapped = $this->mapper()->map($exception, $exchange, $operation);

                if (! $mapped->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $mapped;
                }

                $this->sleep($this->retryDelayMs * (2 ** ($attempt - 1)));
            }
        }
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private function mapper(): ExchangeErrorMapper
    {
        return $this->mapper ?? new ExchangeErrorMapper;
    }
}
