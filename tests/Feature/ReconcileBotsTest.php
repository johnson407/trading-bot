<?php

use App\Domain\Bot\BotReconciler;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function liveBotForReconcile(string $quoteBalance = '1000'): array
{
    config(['trading.live_enabled' => true]);

    $bot = Bot::factory()->running()->live()->create([
        'quote_inventory' => '1000',
        'base_inventory' => '0',
    ]);
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '100']);
    $order = Order::factory()->for($bot)->create([
        'mode' => 'live',
        'grid_id' => $grid->id,
        'grid_level_id' => $level->id,
        'exchange_order_id' => 'e1',
        'price' => '100',
        'quantity' => '1',
        'filled_quantity' => '0',
        'status' => OrderStatus::Open,
    ]);
    $fake = new FakeExchangeClient(quoteBalance: $quoteBalance);

    return [$bot, $order, $fake, $level];
}

function reconcilerFor(FakeExchangeClient $fake): BotReconciler
{
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    return app(BotReconciler::class);
}

it('keeps matching open orders running', function () {
    [$bot, $order, $fake] = liveBotForReconcile('1000');
    $fake->orders['e1'] = \App\Domain\Exchange\ValueObjects\ExchangeOrder::make(
        'e1', $order->client_order_id, OrderStatus::Open, $order->side, \App\Domain\Support\Decimal::of('100'), \App\Domain\Support\Decimal::of('1'),
    );

    $result = reconcilerFor($fake)->reconcile($bot);

    expect($result->ok())->toBeTrue()
        ->and($result->matched)->toBe(1)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
});

it('applies a filled remote order and keeps the bot running', function () {
    [$bot, $order, $fake] = liveBotForReconcile('900');
    $fake->orders['e1'] = \App\Domain\Exchange\ValueObjects\ExchangeOrder::make(
        'e1', $order->client_order_id, OrderStatus::Filled, $order->side, \App\Domain\Support\Decimal::of('100'), \App\Domain\Support\Decimal::of('1'), \App\Domain\Support\Decimal::of('1'), \App\Domain\Support\Decimal::of('100'),
    );

    $result = reconcilerFor($fake)->reconcile($bot);


    expect($result->ok())->toBeTrue()
        ->and($result->filled)->toBe(1)
        ->and($bot->fresh()->base_inventory)->toBe('1.0000000000000000')
        ->and($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and(Fill::where('bot_id', $bot->id)->count())->toBe(1);
});

it('cancels a locally open order that the exchange reports canceled', function () {
    [$bot, $order, $fake] = liveBotForReconcile('1000');
    $fake->orders['e1'] = \App\Domain\Exchange\ValueObjects\ExchangeOrder::make(
        'e1', $order->client_order_id, OrderStatus::Canceled, $order->side, \App\Domain\Support\Decimal::of('100'), \App\Domain\Support\Decimal::of('1'),
    );

    $result = reconcilerFor($fake)->reconcile($bot);

    expect($result->ok())->toBeTrue()
        ->and($result->canceled)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled);
});

it('pauses on an unknown exchange order', function () {
    [$bot] = liveBotForReconcile('1000');
    $fake = new FakeExchangeClient(quoteBalance: '1000');
    $fake->orders['orphan'] = \App\Domain\Exchange\ValueObjects\ExchangeOrder::make(
        'orphan', 'unknown', OrderStatus::Open, \App\Domain\Order\Enums\OrderSide::Buy, \App\Domain\Support\Decimal::of('90'), \App\Domain\Support\Decimal::of('1'),
    );

    $result = reconcilerFor($fake)->reconcile($bot);

    expect($result->status)->toBe('conflict')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($result->conflicts)->not->toBeEmpty();
});

it('pauses when the quote balance is outside tolerance', function () {
    [$bot, $order, $fake] = liveBotForReconcile('500');
    $fake->orders['e1'] = \App\Domain\Exchange\ValueObjects\ExchangeOrder::make(
        'e1', $order->client_order_id, OrderStatus::Open, $order->side, \App\Domain\Support\Decimal::of('100'), \App\Domain\Support\Decimal::of('1'),
    );

    $result = reconcilerFor($fake)->reconcile($bot);

    expect($result->status)->toBe('conflict')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused);
});
