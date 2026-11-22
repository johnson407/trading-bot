<?php

use App\Actions\CancelOrder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exit\ExitGuardMonitor;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function exitGuardBot(array $attributes = []): Bot
{
    return Bot::factory()
        ->for(User::factory())
        ->running()
        ->create(array_merge([
            'base_inventory' => '1',
            'average_entry_price' => '100',
            'quote_inventory' => '1000',
            'stop_loss_percent' => '10',
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ], $attributes));
}

it('pauses and cancels orders when the stop-loss triggers', function () {
    $bot = exitGuardBot();
    $order = Order::factory()->for($bot)->create(['status' => 'open']);

    $signal = app(ExitGuardMonitor::class)->evaluate($bot, '89');

    $bot->refresh();

    expect($signal?->rule->value)->toBe('stop_loss')
        ->and($bot->status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status->value)->toBe('canceled')
        ->and(Decimal::of($bot->base_inventory)->toString())->toBe('1')
        ->and(Decimal::of($bot->quote_inventory)->toString())->toBe('1000')
        ->and($bot->riskEvents()->where('type', 'risk.stop_loss_triggered')->count())->toBe(1)
        ->and($bot->activityLogs()->where('type', 'bot.exit_guard_triggered')->count())->toBe(1);
});

it('pauses when the take-profit triggers', function () {
    $bot = exitGuardBot([
        'stop_loss_percent' => null,
        'take_profit_percent' => '10',
    ]);

    $signal = app(ExitGuardMonitor::class)->evaluate($bot, '111');

    expect($signal?->rule->value)->toBe('take_profit')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->riskEvents()->where('type', 'risk.take_profit_triggered')->count())->toBe(1);
});

it('pauses on a range exit without requiring inventory', function () {
    $bot = exitGuardBot([
        'base_inventory' => '0',
        'average_entry_price' => '0',
        'stop_loss_percent' => null,
        'range_low' => '80',
        'range_high' => '120',
    ]);

    $signal = app(ExitGuardMonitor::class)->evaluate($bot, '79');

    expect($signal?->rule->value)->toBe('range_exit')
        ->and($signal?->bound)->toBe('low')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($bot->riskEvents()->where('type', 'risk.range_exit_triggered')->count())->toBe(1);
});

it('leaves a running bot untouched when no rule triggers', function () {
    $bot = exitGuardBot([
        'range_low' => '80',
        'range_high' => '120',
    ]);

    $signal = app(ExitGuardMonitor::class)->evaluate($bot, '100');

    expect($signal)->toBeNull()
        ->and($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and($bot->riskEvents()->count())->toBe(0);
});

it('triggers once and ignores later evaluations once paused', function () {
    $bot = exitGuardBot();

    $first = app(ExitGuardMonitor::class)->evaluate($bot, '89');
    $second = app(ExitGuardMonitor::class)->evaluate($bot->fresh(), '80');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($bot->riskEvents()->where('type', 'risk.stop_loss_triggered')->count())->toBe(1)
        ->and($bot->activityLogs()->where('type', 'bot.exit_guard_triggered')->count())->toBe(1);
});

it('records an exit cancellation failure without using the kill-switch event', function () {
    $bot = exitGuardBot();
    $order = Order::factory()->for($bot)->create(['status' => 'open']);

    $this->mock(CancelOrder::class, function (MockInterface $mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('exchange refused cancellation'));
    });

    $signal = app(ExitGuardMonitor::class)->evaluate($bot, '89');

    expect($signal?->rule->value)->toBe('stop_loss')
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status->value)->toBe('open')
        ->and($bot->riskEvents()->where('type', 'risk.exit_cancel_failed')->count())->toBe(1)
        ->and($bot->riskEvents()->where('type', 'risk.exit_cancel_failed')->sole()->severity)->toBe(RiskSeverity::Critical->value)
        ->and($bot->riskEvents()->where('type', 'risk.kill_switch_cancel_failed')->count())->toBe(0);
});

it('continues canceling the remaining orders after one failure', function () {
    $bot = exitGuardBot();
    $bad = Order::factory()->for($bot)->create(['status' => 'open']);
    $good = Order::factory()->for($bot)->create(['status' => 'open']);

    $this->mock(CancelOrder::class, function (MockInterface $mock) use ($bad) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Order $order) use ($bad) {
            if ($order->is($bad)) {
                throw new RuntimeException('exchange refused cancellation');
            }

            $order->forceFill([
                'status' => OrderStatus::Canceled,
                'closed_at' => now(),
            ])->save();

            return $order;
        });
    });

    app(ExitGuardMonitor::class)->evaluate($bot, '89');

    expect($bad->fresh()->status->value)->toBe('open')
        ->and($good->fresh()->status->value)->toBe('canceled')
        ->and($bot->riskEvents()->where('type', 'risk.exit_cancel_failed')->count())->toBe(1)
        ->and($bot->riskEvents()->where('type', 'risk.exit_cancel_failed')->sole()->severity)->toBe(RiskSeverity::Critical->value);
});
