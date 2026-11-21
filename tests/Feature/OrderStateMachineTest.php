<?php

use App\Domain\Order\ClientOrderId;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\InvalidOrderTransitionException;
use App\Domain\Order\OrderPlacementService;
use App\Domain\Order\OrderStateMachine;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies every legal order transition and stamps timestamps', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $machine = app(OrderStateMachine::class);

    $machine->transition($order, OrderStatus::Open);
    expect($order->status)->toBe(OrderStatus::Open)->and($order->opened_at)->not->toBeNull();

    $machine->transition($order, OrderStatus::PartiallyFilled);
    $machine->transition($order, OrderStatus::Filled);

    expect($order->status)->toBe(OrderStatus::Filled)->and($order->closed_at)->not->toBeNull();

    $canceled = Order::factory()->create(['status' => OrderStatus::Open]);
    $machine->transition($canceled, OrderStatus::Canceled);
    expect($canceled->status)->toBe(OrderStatus::Canceled);
});

it('rejects illegal order transitions', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Filled]);

    expect(fn () => app(OrderStateMachine::class)->transition($order, OrderStatus::Open))
        ->toThrow(InvalidOrderTransitionException::class);
});

it('builds deterministic client order ids', function () {
    $bot = Bot::factory()->create();
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create(['sequence' => 3]);

    expect(ClientOrderId::for($bot, $level, 2))
        ->toBe("bot-{$bot->id}-grid-{$grid->id}-level-3-cycle-2");
});

it('does not duplicate orders on repeated placement requests', function () {
    $bot = Bot::factory()->create();
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create(['sequence' => 1]);
    $service = app(OrderPlacementService::class);

    $first = $service->request($bot, $grid, $level, OrderSide::Buy, '100', '1', 1);
    $second = $service->request($bot, $grid, $level, OrderSide::Buy, '100', '1', 1);

    expect($first->is($second))->toBeTrue()
        ->and(Order::where('bot_id', $bot->id)->count())->toBe(1)
        ->and($first->client_order_id)->toBe(ClientOrderId::for($bot, $level, 1));
});
