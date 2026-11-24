<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function botDesiredStatusMigrationPath(): string
{
    return 'database/migrations/2026_11_23_000100_add_desired_status_to_bots_table.php';
}

it('adds desired_status with an idle default and a status backfill', function () {
    expect(Schema::hasColumn('bots', 'desired_status'))->toBeTrue();

    $default = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'bots')
        ->where('column_name', 'desired_status')
        ->value('column_default');

    expect((string) $default)->toContain('idle');

    $this->artisan('migrate:rollback', [
        '--path' => botDesiredStatusMigrationPath(),
    ])->assertSuccessful();

    expect(Schema::hasColumn('bots', 'desired_status'))->toBeFalse();

    $user = User::factory()->create();
    $runningAttributes = Bot::factory()->make([
        'user_id' => $user->id,
        'status' => BotStatus::Running,
    ])->getAttributes();
    unset($runningAttributes['desired_status']);
    $pausedAttributes = Bot::factory()->make([
        'user_id' => $user->id,
        'status' => BotStatus::Paused,
    ])->getAttributes();
    unset($pausedAttributes['desired_status']);

    $runningId = DB::table('bots')->insertGetId($runningAttributes);
    $pausedId = DB::table('bots')->insertGetId($pausedAttributes);
    $running = Bot::query()->findOrFail($runningId);
    $paused = Bot::query()->findOrFail($pausedId);

    $this->artisan('migrate', [
        '--path' => botDesiredStatusMigrationPath(),
    ])->assertSuccessful();

    expect(DB::table('bots')->where('id', $running->id)->value('desired_status'))->toBe('running')
        ->and(DB::table('bots')->where('id', $paused->id)->value('desired_status'))->toBe('paused')
        ->and($running->fresh()->desired_status)->toBe(BotStatus::Running)
        ->and($paused->fresh()->desired_status)->toBe(BotStatus::Paused);
});

it('defaults a new bot to desired_status idle', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->create([
        'user_id' => $user->id,
        'status' => BotStatus::Running,
    ]);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Idle)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running);
});
