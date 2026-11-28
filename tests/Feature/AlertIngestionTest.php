<?php

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Models\User;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('ingests an engine risk event and marks it processed', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $event = RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'message' => 'Daily loss limit breached.',
    ]);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    $alert = Alert::query()->sole();

    expect($alert->type)->toBe('risk.daily_loss_limit')
        ->and($alert->source_type)->toBe(RiskEvent::class)
        ->and($alert->source_id)->toBe($event->id)
        ->and($event->fresh()->alert_processed_at)->not->toBeNull();
});

it('ingests an engine activity event and marks it processed', function () {
    $user = User::factory()->create();
    $activity = ActivityLog::factory()->create([
        'user_id' => $user->id,
        'type' => 'kill_switch.activated',
        'severity' => 'critical',
        'message' => 'Kill switch activated.',
    ]);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    expect(Alert::query()->sole()->source_id)->toBe($activity->id)
        ->and($activity->fresh()->alert_processed_at)->not->toBeNull();
});

it('marks non-alertable engine events processed without creating alerts', function () {
    $event = RiskEvent::factory()->create([
        'type' => 'risk.kill_switch_activated',
        'severity' => 'critical',
    ]);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    expect(Alert::query()->count())->toBe(0)
        ->and($event->fresh()->alert_processed_at)->not->toBeNull();
});

it('marks source rows processed after a Laravel-side alert attempt and never re-alerts them', function () {
    $bot = Bot::factory()->create();

    $event = app(RiskEventRecorder::class)->record(
        'risk.daily_loss_limit',
        'Daily loss limit breached.',
        $bot,
    );
    $activity = app(ActivityRecorder::class)->record(
        'kill_switch.activated',
        'Kill switch activated.',
        bot: null,
        user: $bot->user,
    );

    expect($event->fresh()->alert_processed_at)->not->toBeNull()
        ->and($activity->fresh()->alert_processed_at)->not->toBeNull()
        ->and(Alert::query()->count())->toBe(2);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    expect(Alert::query()->count())->toBe(2);
});

it('retries a failed ingestion and marks the row processed after three attempts', function () {
    $event = RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    Schema::rename('alerts', 'alerts_unavailable');

    try {
        $this->artisan('alerts:ingest-engine-events')->assertSuccessful();
        $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

        expect($event->fresh()->alert_ingest_attempts)->toBe(2)
            ->and($event->fresh()->alert_processed_at)->toBeNull();

        $this->artisan('alerts:ingest-engine-events')->assertSuccessful();
    } finally {
        Schema::rename('alerts_unavailable', 'alerts');
    }

    expect($event->fresh()->alert_ingest_attempts)->toBe(3)
        ->and($event->fresh()->alert_processed_at)->not->toBeNull();
});

it('reports duplicate alert sources before the unique index is enforced', function () {
    DB::statement('ALTER TABLE alerts DROP CONSTRAINT IF EXISTS alerts_source_type_source_id_unique');

    $user = User::factory()->create();

    Alert::factory()->for($user)->create([
        'source_type' => RiskEvent::class,
        'source_id' => 42,
    ]);
    Alert::factory()->for($user)->create([
        'source_type' => RiskEvent::class,
        'source_id' => 42,
    ]);

    $this->artisan('alerts:check-source-duplicates')
        ->expectsOutputToContain('Duplicate alert sources found')
        ->assertExitCode(1);
});

it('passes the duplicate source check when sources are unique', function () {
    $user = User::factory()->create();
    Alert::factory()->for($user)->create([
        'source_type' => RiskEvent::class,
        'source_id' => 7,
    ]);

    $this->artisan('alerts:check-source-duplicates')->assertSuccessful();
});

it('alerts once for an engine heartbeat outage without changing bot state', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'mode' => 'live',
        'status' => 'running',
        'desired_status' => 'running',
    ]);

    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = false;

    $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();
    $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->sole()->type)->toBe('engine.heartbeat_missing')
        ->and(Alert::query()->sole()->bot_id)->toBeNull()
        ->and($bot->fresh()->status->value)->toBe('running');
});

it('does not alert for heartbeat outages without a live bot', function () {
    Bot::factory()->create(['mode' => 'paper', 'status' => 'running']);
    app(EngineHeartbeat::class)->available = false;

    $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

    expect(Alert::query()->count())->toBe(0);
});

it('can disable engine ingestion and heartbeat checks', function () {
    config(['alerts.engine_ingest_enabled' => false]);

    $event = RiskEvent::factory()->create(['type' => 'risk.daily_loss_limit']);
    Bot::factory()->create(['mode' => 'live', 'status' => 'running']);
    app(EngineHeartbeat::class)->available = false;

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();
    $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

    expect(Alert::query()->count())->toBe(0)
        ->and($event->fresh()->alert_processed_at)->toBeNull();
});
