<?php

use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ValueObjects\Balance;
use App\Domain\Exchange\ValueObjects\Candle;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('satisfies every exchange client contract method', function () {
    $client = new FakeExchangeClient(price: '101.5');
    $bot = new Bot;

    $market = $client->market('BTC/USDT');
    expect($market)->toBeInstanceOf(MarketData::class)
        ->and($market->last->toString())->toBe('101.5')
        ->and($market->pricePrecision)->toBe(2)
        ->and($market->amountPrecision)->toBe(6);

    $candles = $client->candles('BTC/USDT', '5m', 2);
    expect($candles)->toHaveCount(2)
        ->and($candles[0])->toBeInstanceOf(Candle::class)
        ->and($candles[0]->close->toString())->toBe('100.5')
        ->and($client->candleCalls)->toBe([
            ['symbol' => 'BTC/USDT', 'timeframe' => '5m', 'limit' => 2],
        ]);

    $balances = $client->balances($bot);
    expect($balances)->toHaveCount(2)
        ->and($balances[0])->toBeInstanceOf(Balance::class)
        ->and($balances[0]->asset)->toBe('USDT')
        ->and($balances[0]->free->toString())->toBe('1000');

    $order = $client->placeLimitOrder(
        $bot,
        'BTC/USDT',
        OrderSide::Buy,
        Decimal::of('100'),
        Decimal::of('1'),
        'client-1',
    );

    expect($order)->toBeInstanceOf(ExchangeOrder::class)
        ->and($order->status)->toBe(OrderStatus::Open)
        ->and($order->clientOrderId)->toBe('client-1')
        ->and($client->order($bot, 'BTC/USDT', $order->exchangeOrderId)->exchangeOrderId)->toBe($order->exchangeOrderId)
        ->and($client->openOrders($bot, 'BTC/USDT'))->toHaveCount(1);

    $client->cancelOrder($bot, 'BTC/USDT', $order->exchangeOrderId);

    expect($client->order($bot, 'BTC/USDT', $order->exchangeOrderId)->status)->toBe(OrderStatus::Canceled);
});

it('normalizes exchange errors and retryability', function () {
    $rateLimit = ExchangeException::make(
        ExchangeErrorType::RateLimit,
        'binance',
        'market',
        'Too many requests.',
    );

    $notFound = ExchangeException::make(
        ExchangeErrorType::NotFound,
        'binance',
        'cancelOrder',
        'Unknown order.',
    );

    expect($rateLimit->isRetryable())->toBeTrue()
        ->and($rateLimit->toArray()['type'])->toBe('rate_limit')
        ->and($notFound->isRetryable())->toBeFalse()
        ->and(ExchangeErrorType::Authentication->isRetryable())->toBeFalse()
        ->and(ExchangeErrorType::Connection->isRetryable())->toBeTrue()
        ->and(ExchangeErrorType::Unsupported->isRetryable())->toBeFalse();
});

it('throws a normalized not-found error', function () {
    $client = new FakeExchangeClient;

    expect(fn () => $client->order(new Bot, 'BTC/USDT', 'missing'))
        ->toThrow(ExchangeException::class, 'Order not found.');
});

it('delegates public candles through the paper client', function () {
    $public = new FakeExchangeClient;
    $paper = new PaperExchangeClient($public);

    $candles = $paper->candles('BTC/USDT', '5m', 2);

    expect($candles)->toHaveCount(2)
        ->and($candles[0])->toBeInstanceOf(Candle::class)
        ->and($public->candleCalls)->toBe([
            ['symbol' => 'BTC/USDT', 'timeframe' => '5m', 'limit' => 2],
        ]);
});
