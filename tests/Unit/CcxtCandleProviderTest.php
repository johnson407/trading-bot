<?php

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Services\Backtest\CcxtCandleProvider;
use ccxt\NetworkError;

function candleRow(string $open, float|int $close): array
{
    $timestamp = (new DateTimeImmutable($open))->getTimestamp() * 1000;

    return [$timestamp, $close, $close, $close, $close, 1];
}

function fakeCcxtClient(array $rows, ?int $failures = 0, ?int $cap = null): object
{
    return new class($rows, $failures, $cap)
    {
        public int $calls = 0;

        public function __construct(private array $rows, private int $failures, private ?int $cap) {}

        public function fetch_ohlcv(string $symbol, string $timeframe, int $since, int $limit): array
        {
            $this->calls++;

            if ($this->calls <= $this->failures) {
                throw new NetworkError('temporary failure');
            }

            $rows = array_values(array_filter($this->rows, fn (array $row) => $row[0] >= $since));

            return array_slice($rows, 0, min($limit, $this->cap ?? $limit));
        }
    };
}

it('fetches a single chunk in ascending order', function () {
    config(['backtest.candle_chunk_limit' => 1000]);
    $client = fakeCcxtClient([
        candleRow('2026-01-01T00:00:00Z', 100),
        candleRow('2026-01-01T00:15:00Z', 101),
    ]);
    $provider = new CcxtCandleProvider(fn () => $client, fn () => null, null, 1, 0);

    $candles = $provider->fetch(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toHaveCount(2)
        ->and($candles[0]->openTime->format(DATE_ATOM))->toBe('2026-01-01T00:00:00+00:00')
        ->and($candles[1]->close->toString())->toBe('101')
        ->and($client->calls)->toBe(2);
});

it('paginates across multiple chunks', function () {
    config(['backtest.candle_chunk_limit' => 2]);
    $client = fakeCcxtClient([
        candleRow('2026-01-01T00:00:00Z', 100),
        candleRow('2026-01-01T00:15:00Z', 101),
        candleRow('2026-01-01T00:30:00Z', 102),
        candleRow('2026-01-01T00:45:00Z', 103),
    ]);
    $provider = new CcxtCandleProvider(fn () => $client, fn () => null, null, 1, 0);

    $candles = $provider->fetch(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toHaveCount(4)
        ->and($client->calls)->toBe(2);
});

it('continues when the exchange caps a batch below the requested limit', function () {
    config(['backtest.candle_chunk_limit' => 5]);
    $client = fakeCcxtClient([
        candleRow('2026-01-01T00:00:00Z', 100),
        candleRow('2026-01-01T00:15:00Z', 101),
        candleRow('2026-01-01T00:30:00Z', 102),
        candleRow('2026-01-01T00:45:00Z', 103),
        candleRow('2026-01-01T01:00:00Z', 104),
        candleRow('2026-01-01T01:15:00Z', 105),
    ], cap: 2);
    $provider = new CcxtCandleProvider(fn () => $client, fn () => null, null, 1, 0);

    $candles = $provider->fetch(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:30:00Z'),
    );

    expect($candles)->toHaveCount(6)
        ->and($client->calls)->toBe(3);
});

it('returns an empty list for an empty response', function () {
    $client = fakeCcxtClient([]);
    $provider = new CcxtCandleProvider(fn () => $client, fn () => null, null, 1, 0);

    $candles = $provider->fetch(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toBe([]);
});

it('retries a transient failure and normalizes a persistent one', function () {
    $client = fakeCcxtClient([candleRow('2026-01-01T00:00:00Z', 100)], 1);
    $provider = new CcxtCandleProvider(fn () => $client, fn () => null, null, 2, 0);

    $candles = $provider->fetch(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toHaveCount(1)
        ->and($client->calls)->toBe(3);

    $failing = fakeCcxtClient([], 5);
    $failingProvider = new CcxtCandleProvider(fn () => $failing, fn () => null, null, 2, 0);

    try {
        $failingProvider->fetch(
            'binance',
            'BTC/USDT',
            Timeframe::M15,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T01:00:00Z'),
        );

        $this->fail('Expected an ExchangeException.');
    } catch (ExchangeException $exception) {
        expect($exception->exchange)->toBe('binance')
            ->and($exception->operation)->toBe('fetchOHLCV');
    }
});
