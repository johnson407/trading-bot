<?php

use App\Actions\CancelOrder;
use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\ConsecutiveFailureTracker;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('pauses a bot after the failure threshold and cancels open orders', function () {
    $bot = Bot::factory()->live()->running()->create();
    $orders = Order::factory()->for($bot)->count(2)->create(['status' => OrderStatus::Open]);
    $tracker = app(ConsecutiveFailureTracker::class);

    expect($tracker->recordFailure($bot, 'first'))->toBe(1)
        ->and($tracker->recordFailure($bot, 'second'))->toBe(2)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running);

    expect($tracker->recordFailure($bot, 'third'))->toBe(3);

    $fresh = $bot->fresh();

    expect($fresh->status)->toBe(BotStatus::Paused)
        ->and($fresh->mode)->toBe(BotMode::Live)
        ->and($fresh->last_error)->toBe('third')
        ->and($orders->fresh()->every(fn (Order $order) => $order->status === OrderStatus::Canceled))->toBeTrue()
        ->and($fresh->activityLogs()->where('type', 'exchange.failure_threshold')->count())->toBe(1)
        ->and($fresh->riskEvents()->where('type', 'exchange.failure_threshold')->count())->toBe(1);
});

it('resets the failure count after a success', function () {
    $bot = Bot::factory()->running()->create(['consecutive_failures' => 2]);
    $tracker = app(ConsecutiveFailureTracker::class);

    $tracker->recordSuccess($bot);

    expect($bot->fresh()->consecutive_failures)->toBe(0);
});

it('does not switch a live bot to paper when it pauses', function () {
    $bot = Bot::factory()->live()->running()->create();
    $tracker = app(ConsecutiveFailureTracker::class);

    $tracker->recordFailure($bot, 'failure');
    $tracker->recordFailure($bot, 'failure');
    $tracker->recordFailure($bot, 'failure');

    expect($bot->fresh()->mode)->toBe(BotMode::Live);
});

it('records a cancellation-failure incident when pausing and cancellation fails', function () {
    $bot = Bot::factory()->live()->running()->create();
    Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $cancel = Mockery::mock(CancelOrder::class);
    $cancel->shouldReceive('handle')->andThrow(new RuntimeException('cancel failed'));
    app()->instance(CancelOrder::class, $cancel);

    $tracker = app(ConsecutiveFailureTracker::class);
    $tracker->recordFailure($bot, 'first');
    $tracker->recordFailure($bot, 'second');
    $tracker->recordFailure($bot, 'third');

    expect($bot->fresh()->riskEvents()->where('type', 'risk.failure_cancel_failed')->count())->toBe(1)
        ->and(Alert::query()->where('type', 'risk.failure_cancel_failed')->count())->toBe(1);
});
