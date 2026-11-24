<?php

use App\Actions\CancelOrder;
use App\Actions\PauseBot;
use App\Actions\StartBot;
use App\Actions\StopBot;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts an idle bot and records the event once', function () {
    $bot = Bot::factory()->create(['status' => BotStatus::Idle]);

    $started = app(StartBot::class)->handle($bot);
    $again = app(StartBot::class)->handle($started);

    expect($started->desired_status)->toBe(BotStatus::Running)
        ->and($started->status)->toBe(BotStatus::Idle)
        ->and($again->desired_status)->toBe(BotStatus::Running)
        ->and($bot->activityLogs()->where('type', 'bot.started')->count())->toBe(1);
});

it('pauses a running bot while preserving its open orders', function () {
    $bot = Bot::factory()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $paused = app(PauseBot::class)->handle($bot);

    expect($paused->desired_status)->toBe(BotStatus::Paused)
        ->and($paused->status)->toBe(BotStatus::Running)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open)
        ->and($bot->activityLogs()->where('type', 'bot.paused')->count())->toBe(1);
});

it('stops a live bot and cancels every open order when the engine is unavailable', function () {
    app(EngineHeartbeat::class)->available = false;

    $bot = Bot::factory()->live()->running()->create();

    $pending = Order::factory()->for($bot)->create(['status' => OrderStatus::Pending]);
    $open = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);
    $partial = Order::factory()->for($bot)->create(['status' => OrderStatus::PartiallyFilled]);
    $filled = Order::factory()->for($bot)->create(['status' => OrderStatus::Filled]);

    $stopped = app(StopBot::class)->handle($bot);

    expect($stopped->desired_status)->toBe(BotStatus::Stopped)
        ->and($pending->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($open->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($partial->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($filled->fresh()->status)->toBe(OrderStatus::Filled)
        ->and($bot->orders()->whereIn('status', ['canceled'])->count())->toBe(3);
});

it('cancels live orders when the stop command cannot be published', function () {
    app(CommandPublisher::class)->fail = true;

    $bot = Bot::factory()->live()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $stopped = app(StopBot::class)->handle($bot);

    expect($stopped->desired_status)->toBe(BotStatus::Stopped)
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled);
});

it('cancels live orders locally when the heartbeat is unavailable', function () {
    app(EngineHeartbeat::class)->available = false;

    $bot = Bot::factory()->live()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $stopped = app(StopBot::class)->handle($bot);

    expect($stopped->desired_status)->toBe(BotStatus::Stopped)
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and(app(CommandPublisher::class)->ofType('bot.stop'))->toHaveCount(1);
});

it('leaves paper orders to the engine when stopping a paper bot', function () {
    $bot = Bot::factory()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $stopped = app(StopBot::class)->handle($bot);

    expect($stopped->desired_status)->toBe(BotStatus::Stopped)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
});

it('resumes without duplicating existing orders', function () {
    $bot = Bot::factory()->create(['status' => BotStatus::Paused]);
    Order::factory()->for($bot)->create([
        'status' => OrderStatus::Open,
        'type' => OrderType::Limit,
    ]);

    app(StartBot::class)->handle($bot);

    expect($bot->orders()->count())->toBe(1);
});

it('cancels an already terminal order idempotently', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Canceled]);

    $result = app(CancelOrder::class)->handle($order);

    expect($result->is($order))->toBeTrue()
        ->and($result->status)->toBe(OrderStatus::Canceled)
        ->and($order->bot->activityLogs()->where('type', 'order.canceled')->count())->toBe(0);
});
