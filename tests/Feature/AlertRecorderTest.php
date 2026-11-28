<?php

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Alerts\AlertRecorder;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('creates an alert from a covered risk event with its source and severity', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $event = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'message' => 'Daily loss limit breached; bot paused.',
        'context' => ['loss' => '150', 'limit' => '100'],
    ]);

    $alert = app(AlertRecorder::class)->fromRiskEvent($event);

    expect($alert)->not->toBeNull()
        ->and($alert->user_id)->toBe($user->id)
        ->and($alert->bot_id)->toBe($bot->id)
        ->and($alert->type)->toBe('risk.daily_loss_limit')
        ->and($alert->severity)->toBe('critical')
        ->and($alert->title)->toBe('Daily loss limit reached')
        ->and($alert->message)->toBe('Daily loss limit breached; bot paused.')
        ->and($alert->context)->toBe(['loss' => '150', 'limit' => '100'])
        ->and($alert->source_type)->toBe(RiskEvent::class)
        ->and($alert->source_id)->toBe($event->id)
        ->and($alert->source->is($event))->toBeTrue();
});

it('reports a duplicate source across dedupe windows as deduplicated', function () {
    $this->freezeTime();

    $event = RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    $recorder = app(AlertRecorder::class);
    $first = $recorder->recordRiskEvent($event);

    $this->travel(901)->seconds();

    $second = $recorder->recordRiskEvent($event);

    expect($first->status->value)->toBe('created')
        ->and($second->status->value)->toBe('deduplicated')
        ->and($second->alert?->is($first->alert))->toBeTrue()
        ->and(Alert::query()->count())->toBe(1);
});

it('skips risk event types that are not in the incident catalog', function () {
    $event = RiskEvent::factory()->create([
        'type' => 'risk.position_limit',
        'severity' => 'critical',
    ]);

    expect(app(AlertRecorder::class)->fromRiskEvent($event))->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
});

it('does not create alerts when alerting is disabled', function () {
    config(['alerts.enabled' => false]);

    $event = RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    expect(app(AlertRecorder::class)->fromRiskEvent($event))->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
});

it('suppresses a duplicate in the same deduplication bucket', function () {
    $this->freezeTime();

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $recorder = app(AlertRecorder::class);

    $first = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'exchange.failure_threshold',
        'severity' => 'critical',
    ]);
    $second = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'exchange.failure_threshold',
        'severity' => 'critical',
    ]);

    expect($recorder->fromRiskEvent($first))->not->toBeNull()
        ->and($recorder->fromRiskEvent($second))->toBeNull()
        ->and(Alert::query()->count())->toBe(1);
});

it('creates a new alert after the deduplication window has passed', function () {
    $this->freezeTime();

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $recorder = app(AlertRecorder::class);

    $first = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.stale_bot',
        'severity' => 'warning',
    ]);

    $recorder->fromRiskEvent($first);

    $this->travel(901)->seconds();

    $second = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.stale_bot',
        'severity' => 'warning',
    ]);

    expect($recorder->fromRiskEvent($second))->not->toBeNull()
        ->and(Alert::query()->count())->toBe(2);
});

it('uses an account key when the incident has no bot', function () {
    $user = User::factory()->create();
    $activity = ActivityLog::factory()->create([
        'user_id' => $user->id,
        'bot_id' => null,
        'type' => 'kill_switch.activated',
        'severity' => 'critical',
    ]);

    $alert = app(AlertRecorder::class)->fromActivity($activity);

    expect($alert)->not->toBeNull()
        ->and($alert->bot_id)->toBeNull()
        ->and($alert->dedupe_key)->toBe(hash('sha256', "user:{$user->id}|bot:account|type:kill_switch.activated"));
});

it('creates alerts only for covered risk events through the recorder', function () {
    $bot = Bot::factory()->create();
    $recorder = app(RiskEventRecorder::class);

    $recorder->record('risk.daily_loss_limit', 'Daily loss limit breached.', $bot, severity: RiskSeverity::Critical);
    $recorder->record('risk.position_limit', 'Position limit reached.', $bot, severity: RiskSeverity::Critical);
    $recorder->record('risk.insufficient_inventory', 'Not enough inventory.', $bot);

    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->type)->toBe('risk.daily_loss_limit');
});

it('creates account alerts for kill-switch activation but not routine activity', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $recorder = app(ActivityRecorder::class);

    $recorder->record('kill_switch.activated', 'Kill-switch activated.', user: $user, severity: 'critical');
    $recorder->record('bot.started', 'Bot started.', $bot);

    $alert = Alert::query()->first();

    expect(Alert::query()->count())->toBe(1)
        ->and($alert->type)->toBe('kill_switch.activated')
        ->and($alert->bot_id)->toBeNull()
        ->and($alert->user_id)->toBe($user->id)
        ->and($alert->severity)->toBe('critical');
});

it('collapses the activity and risk pair for exchange failures into one alert', function () {
    $bot = Bot::factory()->create();

    app(ActivityRecorder::class)->record(
        'exchange.failure_threshold',
        'Bot paused after consecutive exchange failures.',
        $bot,
        severity: 'error',
    );

    app(RiskEventRecorder::class)->record(
        'exchange.failure_threshold',
        'Consecutive exchange failures reached the pause threshold.',
        $bot,
        severity: RiskSeverity::Critical,
    );

    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->source_type)->toBe(ActivityLog::class);
});

it('creates an alert for every covered incident type', function () {
    foreach (array_keys(config('alerts.incidents')) as $type) {
        $bot = Bot::factory()->create();

        app(RiskEventRecorder::class)->record(
            $type,
            'Covered incident.',
            $bot,
            severity: RiskSeverity::Critical,
        );

        expect(Alert::query()->where('type', $type)->count())->toBe(1);
    }
});

it('sanitizes secret-bearing context before the alert is stored', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $event = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'message' => 'binance_api_key=abc token: xyz',
        'context' => [
            'loss' => '150',
            'binance_api_key' => 'abc',
            'nested' => ['client_secret' => 'def'],
            'error' => 'Authorization: Bearer xyz',
        ],
    ]);

    $alert = app(AlertRecorder::class)->fromRiskEvent($event);

    expect($alert)->not->toBeNull()
        ->and($alert->message)->not->toContain('abc')
        ->and($alert->message)->not->toContain('xyz')
        ->and($alert->context)->toHaveKey('loss')
        ->and($alert->context)->not->toHaveKey('binance_api_key')
        ->and($alert->context['nested'])->not->toHaveKey('client_secret')
        ->and($alert->context['error'] ?? '')->not->toContain('Bearer xyz');
});

it('contains alert persistence failures without aborting the surrounding transaction', function () {
    Log::spy();

    [$alert, $userId] = DB::transaction(function (): array {
        $event = new RiskEvent([
            'user_id' => 999999,
            'bot_id' => null,
            'type' => 'risk.daily_loss_limit',
            'severity' => 'critical',
            'message' => 'Missing owner.',
            'context' => [],
        ]);
        $event->id = 123;

        $alert = app(AlertRecorder::class)->fromRiskEvent($event);
        $user = User::factory()->create();

        return [$alert, $user->id];
    });

    expect($alert)->toBeNull()
        ->and(User::query()->whereKey($userId)->exists())->toBeTrue()
        ->and(Alert::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')->atLeast()->once();
});
