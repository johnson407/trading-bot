<?php

use App\Actions\CancelOrder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotMode;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function liveOrderForCancellation(FakeExchangeClient $fake): array
{
    $bot = Bot::factory()->running()->live()->create();

    $remote = $fake->placeLimitOrder(
        $bot,
        $bot->symbol,
        OrderSide::Buy,
        Decimal::of('100'),
        Decimal::of('1'),
        'client-1',
    );

    $order = Order::factory()->for($bot)->create([
        'mode' => BotMode::Live,
        'client_order_id' => 'client-1',
        'exchange_order_id' => $remote->exchangeOrderId,
        'status' => OrderStatus::Open,
    ]);

    return [$bot, $order, $remote];
}

it('cancels a live order on the exchange before marking it canceled locally', function () {
    $fake = new FakeExchangeClient;
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, $fake));

    [$bot, $order, $remote] = liveOrderForCancellation($fake);

    app(CancelOrder::class)->handle($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($fake->orders[$remote->exchangeOrderId]->status)->toBe(OrderStatus::Canceled)
        ->and($fake->cancelCalls)->toBe([[
            'symbol' => $bot->symbol,
            'exchange_order_id' => $remote->exchangeOrderId,
            'client_order_id' => 'client-1',
        ]]);
});

it('cancels a live pending intent by client order id when no exchange id is stored', function () {
    $fake = new FakeExchangeClient;
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, $fake));

    [$bot, $order] = liveOrderForCancellation($fake);

    $order->forceFill([
        'exchange_order_id' => null,
        'status' => OrderStatus::Pending,
    ])->save();
    $pending = $order->fresh();

    app(CancelOrder::class)->handle($pending);

    expect($pending->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($fake->cancelCalls)->toBe([[
            'symbol' => $bot->symbol,
            'exchange_order_id' => null,
            'client_order_id' => 'client-1',
        ]]);
});

it('treats an exchange order that is already missing as canceled', function () {
    $fake = new FakeExchangeClient;
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, $fake));

    $bot = Bot::factory()->running()->live()->create();
    $order = Order::factory()->for($bot)->create([
        'mode' => BotMode::Live,
        'exchange_order_id' => 'missing-1',
        'status' => OrderStatus::Open,
    ]);

    $result = app(CancelOrder::class)->handle($order);

    expect($result->status)->toBe(OrderStatus::Canceled)
        ->and($fake->cancelCalls)->toHaveCount(1);
});

it('keeps the local order open when the exchange cancellation fails', function () {
    $fake = new FakeExchangeClient;
    $fake->cancelFailure = ExchangeException::make(
        ExchangeErrorType::Connection,
        'fake',
        'cancelOrder',
        'connection lost',
    );
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, $fake));

    [, $order] = liveOrderForCancellation($fake);

    expect(fn () => app(CancelOrder::class)->handle($order))
        ->toThrow(ExchangeException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Open)
        ->and($order->bot->activityLogs()->where('type', 'order.canceled')->count())->toBe(0);
});

it('reports exchange cancellation failures from the kill switch', function () {
    $fake = new FakeExchangeClient;
    $fake->cancelFailure = ExchangeException::make(
        ExchangeErrorType::Connection,
        'fake',
        'cancelOrder',
        'connection lost',
    );
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, $fake));

    [$bot, $order] = liveOrderForCancellation($fake);

    $result = app(CancelBotOrders::class)->handle($bot);

    expect($result['canceled'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($result['failures'][0]['order_id'])->toBe($order->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open)
        ->and($bot->riskEvents()->where('type', 'risk.kill_switch_cancel_failed')->count())->toBe(1);
});

it('does not call the exchange for paper orders', function () {
    $fake = new FakeExchangeClient;
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, null));

    $bot = Bot::factory()->running()->create();
    $order = Order::factory()->for($bot)->create([
        'mode' => BotMode::Paper,
        'exchange_order_id' => 'paper-1',
        'status' => OrderStatus::Open,
    ]);

    app(CancelOrder::class)->handle($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($fake->cancelCalls)->toBe([]);
});
