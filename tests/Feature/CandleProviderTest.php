<?php

use App\Services\Exchange\CandleProvider;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function candleProvider(FakeExchangeClient $fake): CandleProvider
{
    return new CandleProvider(new ExchangeClientFactory(overrides: ['binance' => $fake]));
}

it('returns public candles and caches them by exchange symbol timeframe and limit', function () {
    $fake = new FakeExchangeClient;
    $provider = candleProvider($fake);

    $result = $provider->get('binance', 'BTC/USDT', '5m', 2);

    expect($result['available'])->toBeTrue()
        ->and($result['error'])->toBeNull()
        ->and($result['points'])->toHaveCount(2)
        ->and($result['points'][0])->toBe(['t' => 1700000000000, 'price' => '100.5'])
        ->and($result['points'][1])->toBe(['t' => 1700000300000, 'price' => '101.5'])
        ->and($fake->candleCalls)->toBe([
            ['symbol' => 'BTC/USDT', 'timeframe' => '5m', 'limit' => 2],
        ]);

    $provider->get('binance', 'BTC/USDT', '5m', 2);

    expect($fake->candleCalls)->toHaveCount(1);
});

it('reuses a cached candle result without calling the exchange', function () {
    $fake = new FakeExchangeClient;
    $provider = candleProvider($fake);

    Cache::put($provider->key('binance', 'BTC/USDT', '5m', 2), [
        'available' => true,
        'points' => [['t' => 1700000000000, 'price' => '99.5']],
        'error' => null,
    ], 60);

    $result = $provider->get('binance', 'BTC/USDT', '5m', 2);

    expect($result['points'][0]['price'])->toBe('99.5')
        ->and($fake->candleCalls)->toBeEmpty();
});

it('caches a failed candle lookup and returns an unavailable result', function () {
    $fake = new FakeExchangeClient;
    $fake->candleFailure = new RuntimeException('exchange offline');
    $provider = candleProvider($fake);

    $first = $provider->get('binance', 'BTC/USDT', '5m', 2);
    $second = $provider->get('binance', 'BTC/USDT', '5m', 2);

    expect($first['available'])->toBeFalse()
        ->and($first['points'])->toBeEmpty()
        ->and($first['error'])->toContain('unavailable')
        ->and($second)->toBe($first)
        ->and($fake->candleCalls)->toHaveCount(1);
});

it('treats an empty candle response as unavailable', function () {
    $fake = new FakeExchangeClient(candles: []);
    $provider = candleProvider($fake);

    $result = $provider->get('binance', 'BTC/USDT', '5m', 2);

    expect($result['available'])->toBeFalse()
        ->and($result['points'])->toBeEmpty()
        ->and($result['error'])->toContain('No price data');
});

it('returns an unavailable result when another request holds the cache lock', function () {
    $fake = new FakeExchangeClient;
    $provider = candleProvider($fake);
    $lock = Cache::lock($provider->key('binance', 'BTC/USDT', '5m', 2).':lock', 10);
    $lock->get();

    try {
        $result = $provider->get('binance', 'BTC/USDT', '5m', 2);
    } finally {
        $lock->release();
    }

    expect($result['available'])->toBeFalse()
        ->and($result['error'])->toContain('loading')
        ->and($fake->candleCalls)->toBeEmpty();
});
