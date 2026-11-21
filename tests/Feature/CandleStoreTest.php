<?php

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Models\MarketCandle;
use App\Services\Backtest\CandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeCandleProvider;

uses(RefreshDatabase::class);

function storeCandle(string $open, string $close = '100'): Candle
{
    return Candle::make($open, $close, $close, $close, $close, '1');
}

it('fetches a range once and then serves it from the cache', function () {
    $provider = new FakeCandleProvider([
        storeCandle('2026-01-01T00:00:00Z'),
        storeCandle('2026-01-01T00:15:00Z'),
        storeCandle('2026-01-01T00:30:00Z'),
        storeCandle('2026-01-01T00:45:00Z'),
    ]);
    $store = new CandleStore($provider);
    $start = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $end = new DateTimeImmutable('2026-01-01T01:00:00Z');

    $first = $store->ensure('binance', 'BTC/USDT', Timeframe::M15, $start, $end);
    $second = $store->ensure('binance', 'BTC/USDT', Timeframe::M15, $start, $end);

    expect($first)->toHaveCount(4)
        ->and($second)->toHaveCount(4)
        ->and($provider->requests)->toHaveCount(1)
        ->and(MarketCandle::query()->count())->toBe(4);
});

it('fetches missing interior spans and fills the gap', function () {
    MarketCandle::query()->create([
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'timeframe' => '15m',
        'open_time' => '2026-01-01 00:00:00',
        'open' => '100', 'high' => '100', 'low' => '100', 'close' => '100', 'volume' => '1',
    ]);
    MarketCandle::query()->create([
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'timeframe' => '15m',
        'open_time' => '2026-01-01 00:30:00',
        'open' => '100', 'high' => '100', 'low' => '100', 'close' => '100', 'volume' => '1',
    ]);

    $provider = new FakeCandleProvider([
        storeCandle('2026-01-01T00:00:00Z'),
        storeCandle('2026-01-01T00:15:00Z'),
        storeCandle('2026-01-01T00:30:00Z'),
        storeCandle('2026-01-01T00:45:00Z'),
    ]);

    $candles = (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toHaveCount(4)
        ->and($provider->requests)->toHaveCount(2)
        ->and(MarketCandle::query()->count())->toBe(4);
});

it('fails rather than simulate across a remaining gap', function () {
    $provider = new FakeCandleProvider([
        storeCandle('2026-01-01T00:00:00Z'),
        storeCandle('2026-01-01T00:15:00Z'),
        storeCandle('2026-01-01T00:30:00Z'),
    ]);

    expect(fn () => (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Historical data gap');
});

it('trims a candle that has not closed yet', function () {
    $provider = new FakeCandleProvider([
        storeCandle('2026-01-01T00:00:00Z'),
        storeCandle('2026-01-01T00:15:00Z'),
        storeCandle('2026-01-01T00:30:00Z'),
    ]);

    $candles = (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
        new DateTimeImmutable('2026-01-01T00:40:00Z'),
    );

    expect($candles)->toHaveCount(2);
});

it('upserts large fetched ranges in bounded chunks', function () {
    $candles = [];
    $start = new DateTimeImmutable('2026-01-01T00:00:00Z');

    for ($i = 0; $i < 7_000; $i++) {
        $candles[] = storeCandle($start->modify("+{$i} minutes")->format(DATE_ATOM));
    }

    $provider = new FakeCandleProvider($candles);
    $stream = (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M1,
        $start,
        $start->modify('+7000 minutes'),
    );

    expect($stream)->toHaveCount(7_000)
        ->and(MarketCandle::query()->count())->toBe(7_000)
        ->and($provider->requests)->toHaveCount(2);
});

it('ignores off-grid cached rows when building the stream', function () {
    MarketCandle::query()->create([
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'timeframe' => '15m',
        'open_time' => '2026-01-01 00:07:30',
        'open' => '100', 'high' => '100', 'low' => '100', 'close' => '100', 'volume' => '1',
    ]);

    $provider = new FakeCandleProvider([
        storeCandle('2026-01-01T00:00:00Z'),
        storeCandle('2026-01-01T00:15:00Z'),
        storeCandle('2026-01-01T00:30:00Z'),
        storeCandle('2026-01-01T00:45:00Z'),
    ]);

    $stream = (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($stream)->toHaveCount(4)
        ->and(iterator_to_array($stream))->toHaveCount(4)
        ->and(MarketCandle::query()->count())->toBe(5);
});

it('returns an empty list when no data is available', function () {
    $provider = new FakeCandleProvider;
    $candles = (new CandleStore($provider))->ensure(
        'binance',
        'BTC/USDT',
        Timeframe::M15,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2026-01-01T01:00:00Z'),
    );

    expect($candles)->toHaveCount(0)
        ->and(iterator_to_array($candles))->toBe([]);
});
