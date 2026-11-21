<?php

use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('fills buy and sell orders when the market crosses their limit', function () {
    $paper = new PaperExchangeClient(new FakeExchangeClient(price: '95'));
    $market = MarketData::make('BTC/USDT', '95');
    $bot = Bot::factory()->make();

    $buy = Order::factory()->for($bot)->make([
        'side' => OrderSide::Buy,
        'type' => OrderType::Limit,
        'price' => '100',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);
    $sell = Order::factory()->for($bot)->make([
        'side' => OrderSide::Sell,
        'type' => OrderType::Limit,
        'price' => '110',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);

    $fills = $paper->qualifyingFills($market, [$buy, $sell]);

    expect($fills)->toHaveCount(1)
        ->and($fills[0]->side)->toBe(OrderSide::Buy)
        ->and($fills[0]->price->toString())->toBe('100');
});

it('returns multiple qualifying levels in market-distance order', function () {
    $paper = new PaperExchangeClient(new FakeExchangeClient(price: '75'));
    $market = MarketData::make('BTC/USDT', '75');
    $bot = Bot::factory()->make();

    $far = Order::factory()->for($bot)->make([
        'side' => OrderSide::Buy,
        'price' => '80',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);
    $near = Order::factory()->for($bot)->make([
        'side' => OrderSide::Buy,
        'price' => '90',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);

    $fills = $paper->qualifyingFills($market, [$far, $near]);

    expect($fills)->toHaveCount(2)
        ->and($fills[0]->clientOrderId)->toBe($far->client_order_id)
        ->and($fills[1]->clientOrderId)->toBe($near->client_order_id);
});

it('calculates configurable paper fees at the limit price', function () {
    $paper = new PaperExchangeClient(new FakeExchangeClient);
    $market = MarketData::make('BTC/USDT', '90');
    $order = Order::factory()->make([
        'side' => OrderSide::Buy,
        'price' => '100',
        'quantity' => '2',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);

    $fill = $paper->qualifyingFills($market, [$order], '0.001')[0];

    expect($fill->fee->toString())->toBe('0.2')
        ->and($fill->feeCurrency)->toBe('USDT');
});

it('rejects private and order-placing calls in paper mode', function (string $method) {
    $paper = new PaperExchangeClient(new FakeExchangeClient);
    $bot = Bot::factory()->make();

    $call = match ($method) {
        'balances' => fn () => $paper->balances($bot),
        'place' => fn () => $paper->placeLimitOrder($bot, 'BTC/USDT', OrderSide::Buy, \App\Domain\Support\Decimal::of('100'), \App\Domain\Support\Decimal::of('1'), 'x'),
        'cancel' => fn () => $paper->cancelOrder($bot, 'BTC/USDT', 'x'),
        'order' => fn () => $paper->order($bot, 'BTC/USDT', 'x'),
        'open' => fn () => $paper->openOrders($bot, 'BTC/USDT'),
    };

    try {
        $call();
        $this->fail('Expected an unsupported operation.');
    } catch (ExchangeException $exception) {
        expect($exception->type)->toBe(ExchangeErrorType::Unsupported)
            ->and($exception->exchange)->toBe('paper');
    }
})->with(['balances', 'place', 'cancel', 'order', 'open']);
