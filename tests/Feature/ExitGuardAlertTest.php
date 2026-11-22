<?php

use App\Actions\CancelOrder;
use App\Domain\Exit\ExitGuardMonitor;
use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function exitAlertBot(array $attributes = []): Bot
{
    return Bot::factory()
        ->for(User::factory())
        ->running()
        ->create(array_merge([
            'base_inventory' => '1',
            'average_entry_price' => '100',
            'stop_loss_percent' => '10',
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ], $attributes));
}

it('creates a critical owner alert for a stop-loss', function () {
    $bot = exitAlertBot();

    app(ExitGuardMonitor::class)->evaluate($bot, '89');

    $alert = Alert::query()->where('user_id', $bot->user_id)->sole();

    expect($alert->type)->toBe('risk.stop_loss_triggered')
        ->and($alert->severity)->toBe('critical')
        ->and($alert->bot_id)->toBe($bot->id)
        ->and($alert->source_type)->toBe(RiskEvent::class)
        ->and($alert->source_id)->not->toBeNull()
        ->and($alert->read_at)->toBeNull()
        ->and($alert->context['rule'])->toBe('stop_loss');
});

it('keeps a take-profit alert in the app without sending email', function () {
    Queue::fake();

    $bot = exitAlertBot([
        'stop_loss_percent' => null,
        'take_profit_percent' => '10',
    ]);

    app(ExitGuardMonitor::class)->evaluate($bot, '111');

    $alert = Alert::query()->where('user_id', $bot->user_id)->sole();

    expect($alert->type)->toBe('risk.take_profit_triggered')
        ->and($alert->severity)->toBe('warning');

    Queue::assertNotPushed(SendAlertEmail::class);
});

it('creates a warning owner alert for a range exit', function () {
    $bot = exitAlertBot([
        'base_inventory' => '0',
        'average_entry_price' => '0',
        'stop_loss_percent' => null,
        'range_low' => '80',
        'range_high' => '120',
    ]);

    app(ExitGuardMonitor::class)->evaluate($bot, '79');

    $alert = Alert::query()->where('user_id', $bot->user_id)->sole();

    expect($alert->type)->toBe('risk.range_exit_triggered')
        ->and($alert->severity)->toBe('warning')
        ->and($alert->context['bound'])->toBe('low');
});

it('creates a critical alert when exit cancellation fails', function () {
    $bot = exitAlertBot();
    Order::factory()->for($bot)->create(['status' => 'open']);

    $this->mock(CancelOrder::class, function (MockInterface $mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('exchange refused cancellation'));
    });

    app(ExitGuardMonitor::class)->evaluate($bot, '89');

    $alert = Alert::query()
        ->where('user_id', $bot->user_id)
        ->where('type', 'risk.exit_cancel_failed')
        ->sole();

    expect($alert->severity)->toBe('critical')
        ->and($alert->bot_id)->toBe($bot->id);
});
