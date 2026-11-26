<?php

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function botPauseProvenanceMigrationPath(): string
{
    return 'database/migrations/2026_11_27_000100_add_pause_provenance_to_bots_table.php';
}

it('adds pause provenance without backfilling an existing paused bot', function () {
    expect(Schema::hasColumn('bots', 'pause_reason'))->toBeTrue()
        ->and(Schema::hasColumn('bots', 'pause_reason_recorded_at'))->toBeTrue();

    $this->artisan('migrate:rollback', [
        '--path' => botPauseProvenanceMigrationPath(),
    ])->assertSuccessful();

    expect(Schema::hasColumn('bots', 'pause_reason'))->toBeFalse()
        ->and(Schema::hasColumn('bots', 'pause_reason_recorded_at'))->toBeFalse();

    $user = User::factory()->create();
    $attributes = Bot::factory()->make([
        'user_id' => $user->id,
        'status' => BotStatus::Paused,
        'desired_status' => BotStatus::Running,
    ])->getAttributes();
    $botID = DB::table('bots')->insertGetId($attributes);

    $this->artisan('migrate', [
        '--path' => botPauseProvenanceMigrationPath(),
    ])->assertSuccessful();

    $paused = DB::table('bots')->where('id', $botID)->first();

    expect($paused->pause_reason)->toBeNull()
        ->and($paused->pause_reason_recorded_at)->toBeNull();
});

it('rejects a pause cause outside the fixed set', function () {
    $bot = Bot::factory()->create();

    expect(fn () => DB::table('bots')->where('id', $bot->id)->update([
        'pause_reason' => 'not_a_cause',
        'pause_reason_recorded_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('does not mass assign engine-owned pause provenance', function () {
    $bot = Bot::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Pause provenance mass assignment',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => BotMode::Paper,
        'desired_status' => BotStatus::Idle,
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'tick_interval_seconds' => 60,
        'settings' => [],
        'pause_reason' => 'stale_tick',
        'pause_reason_recorded_at' => now(),
    ]);

    expect($bot->fresh()->pause_reason)->toBeNull()
        ->and($bot->fresh()->pause_reason_recorded_at)->toBeNull();
});
