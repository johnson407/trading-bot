<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\DailyLossMonitor;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Jobs\SendAlertEmail;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class);

it('renders an alert email with the incident and redacted secrets', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Grid One']);
    $alert = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'title' => 'Daily loss limit reached',
        'message' => 'Bot paused after api_key=super-secret.',
        'context' => [
            'loss' => '150',
            'limit' => '100',
            'api_key' => 'super-secret',
            'nested' => ['api_secret' => 'hidden'],
        ],
    ]);

    $html = (new AlertMail($alert))->render();

    expect($html)->toContain('Grid One')
        ->toContain('Daily loss limit reached')
        ->toContain('150')
        ->toContain(route('alerts.index'))
        ->not->toContain('super-secret')
        ->not->toContain('hidden')
        ->not->toContain('api_key')
        ->not->toContain('api_secret');
});

it('sends an alert email to the owner', function () {
    Mail::fake();

    $alert = Alert::factory()->create();

    (new SendAlertEmail($alert->id))->handle();

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo($alert->user->email)
        && $mail->alert->is($alert));
});

it('does nothing when the alert no longer exists', function () {
    Mail::fake();

    (new SendAlertEmail(999999))->handle();

    Mail::assertNothingSent();
});

it('logs a transport failure without changing the alert', function () {
    $alert = Alert::factory()->create();

    $mailer = Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
    $mailer->shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
    Mail::swap($mailer);
    Log::spy();

    expect(fn () => (new SendAlertEmail($alert->id))->handle())->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('error')->once();
    expect($alert->fresh()->read_at)->toBeNull();
});

it('logs a terminal job failure', function () {
    Log::spy();

    (new SendAlertEmail(123))->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->once();
});

it('queues a critical alert email after commit', function () {
    Queue::fake();

    $bot = Bot::factory()->create();

    app(RiskEventRecorder::class)->record(
        'risk.daily_loss_limit',
        'Daily loss limit breached; bot paused.',
        $bot,
        severity: RiskSeverity::Critical,
    );

    $alert = Alert::query()->first();

    expect($alert)->not->toBeNull();
    Queue::assertPushed(SendAlertEmail::class, fn (SendAlertEmail $job) => $job->alertId === $alert->id);
});

it('does not queue an email when the producing transaction rolls back', function () {
    Queue::fake();

    $bot = Bot::factory()->create();

    try {
        DB::transaction(function () use ($bot) {
            app(RiskEventRecorder::class)->record(
                'risk.daily_loss_limit',
                'Daily loss limit breached; bot paused.',
                $bot,
                severity: RiskSeverity::Critical,
            );

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Queue::assertNothingPushed();
    expect(Alert::query()->count())->toBe(0);
});

it('keeps the pause and alert when the mail transport fails', function () {
    config(['queue.default' => 'sync']);

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create(['realized_pnl' => '-150']);

    RiskLimit::factory()->forBot($bot)->create([
        'daily_loss_limit' => '100',
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);

    RiskLimit::factory()->create([
        'scope' => 'user',
        'user_id' => $user->id,
        'bot_id' => null,
        'daily_loss_limit' => '1000',
        'day_start' => now('UTC')->startOfDay(),
        'day_start_realized_pnl' => '0',
    ]);

    $mailer = Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
    $mailer->shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    Mail::swap($mailer);
    Log::spy();

    app(DailyLossMonitor::class)->evaluate($bot);

    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->read_at)->toBeNull();

    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('keeps warning alerts in the inbox without queueing email', function () {
    Queue::fake();

    $bot = Bot::factory()->create();

    app(RiskEventRecorder::class)->record(
        'risk.stale_bot',
        'Bot tick loop is stale.',
        $bot,
        severity: RiskSeverity::Warning,
    );

    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->severity)->toBe('warning');

    Queue::assertNothingPushed();
});

it('keeps the alert when queue dispatch fails', function () {
    Log::spy();

    $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

    $bot = Bot::factory()->create();

    app(RiskEventRecorder::class)->record(
        'risk.daily_loss_limit',
        'Daily loss limit breached; bot paused.',
        $bot,
        severity: RiskSeverity::Critical,
    );

    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->read_at)->toBeNull();

    Log::shouldHaveReceived('error')->atLeast()->once();
});
