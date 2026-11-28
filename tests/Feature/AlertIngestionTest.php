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
use Illuminate\Support\Carbon;
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

it('retries failed ingestion with backoff and never discards the source row', function () {
    $event = RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    Schema::rename('alerts', 'alerts_unavailable');

    try {
        $this->artisan('alerts:ingest-engine-events')->assertFailed();
        DB::table('risk_events')->where('id', $event->id)->update(['alert_next_attempt_at' => null]);
        $this->artisan('alerts:ingest-engine-events')->assertFailed();

        expect($event->fresh()->alert_ingest_attempts)->toBe(2)
            ->and($event->fresh()->alert_processed_at)->toBeNull()
            ->and($event->fresh()->alert_next_attempt_at)->not->toBeNull();
    } finally {
        Schema::rename('alerts_unavailable', 'alerts');
    }

    DB::table('risk_events')->where('id', $event->id)->update(['alert_next_attempt_at' => null]);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    expect($event->fresh()->alert_processed_at)->not->toBeNull()
        ->and(Alert::query()->where('type', 'risk.daily_loss_limit')->count())->toBe(1);
});

it('raises one visible failure alert after six failed ingestion attempts', function () {
    $event = RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fail_risk_event_alert() RETURNS trigger AS $$
        BEGIN
            IF NEW.source_type = 'App\Models\RiskEvent' THEN
                RAISE EXCEPTION 'alert insert blocked for test';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
    SQL);
    DB::unprepared('CREATE TRIGGER fail_risk_event_alert_trigger BEFORE INSERT ON alerts FOR EACH ROW EXECUTE FUNCTION fail_risk_event_alert()');

    try {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->artisan('alerts:ingest-engine-events')->assertFailed();
            DB::table('risk_events')->where('id', $event->id)->update(['alert_next_attempt_at' => null]);
        }

        $failure = Alert::query()->where('type', 'alerts.ingestion_failed')->sole();

        expect($event->fresh()->alert_ingest_attempts)->toBe(6)
            ->and($event->fresh()->alert_processed_at)->toBeNull()
            ->and($failure->source_type)->toBe('alerts.ingestion_failed:risk_events')
            ->and($failure->source_id)->toBe($event->id);

        DB::table('risk_events')->where('id', $event->id)->update(['alert_next_attempt_at' => null]);
        $this->artisan('alerts:ingest-engine-events')->assertFailed();

        expect(Alert::query()->where('type', 'alerts.ingestion_failed')->count())->toBe(1);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_risk_event_alert_trigger ON alerts');
        DB::unprepared('DROP FUNCTION IF EXISTS fail_risk_event_alert()');
    }
});

it('reports duplicate alert sources before the unique index is enforced', function () {
    DB::statement('DROP INDEX IF EXISTS alerts_source_type_source_id_unique');

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

it('aborts the ingestion migration when duplicate alert sources remain', function () {
    DB::statement('DROP INDEX IF EXISTS alerts_source_type_source_id_unique');

    $user = User::factory()->create();
    Alert::factory()->for($user)->create([
        'source_type' => RiskEvent::class,
        'source_id' => 99,
    ]);
    Alert::factory()->for($user)->create([
        'source_type' => RiskEvent::class,
        'source_id' => 99,
    ]);

    $migration = require database_path('migrations/2026_11_28_000200_add_alert_ingestion_markers.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'alerts:check-source-duplicates');
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

it('dedupes a sustained outage per window and alerts again after recovery', function () {
    $user = User::factory()->create();
    Bot::factory()->for($user)->create([
        'mode' => 'live',
        'status' => 'running',
        'desired_status' => 'running',
    ]);

    Carbon::setTestNow('2026-01-01 00:00:00');
    $heartbeat = app(EngineHeartbeat::class);
    $heartbeat->available = false;

    try {
        $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();
        $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

        expect(Alert::query()->count())->toBe(1);

        Carbon::setTestNow(now()->addSeconds(901));
        $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

        expect(Alert::query()->count())->toBe(2);

        $heartbeat->available = true;
        Carbon::setTestNow(now()->addSeconds(901));
        $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

        expect(Alert::query()->count())->toBe(2);

        $heartbeat->available = false;
        Carbon::setTestNow(now()->addSeconds(901));
        $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

        expect(Alert::query()->count())->toBe(3);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not alert for heartbeat outages without a live bot', function () {
    Bot::factory()->create(['mode' => 'paper', 'status' => 'running']);
    app(EngineHeartbeat::class)->available = false;

    $this->artisan('alerts:check-engine-heartbeat')->assertSuccessful();

    expect(Alert::query()->count())->toBe(0);
});

it('does not alert for a heartbeat outage when the only live bot is archived', function () {
    $bot = Bot::factory()->create(['mode' => 'live', 'status' => 'running']);
    $bot->delete();
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
