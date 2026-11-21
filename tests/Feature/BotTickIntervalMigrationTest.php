<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function botTickIntervalMigrationPath(): string
{
    return 'database/migrations/2026_11_21_000200_set_default_bot_tick_interval_to_60.php';
}

it('migrates the old default interval to 60 without changing explicit intervals', function () {
    $this->artisan('migrate:rollback', [
        '--path' => botTickIntervalMigrationPath(),
        '--step' => 1,
    ])->assertSuccessful();

    $user = User::factory()->create();
    $oldDefault = Bot::factory()->create([
        'user_id' => $user->id,
        'tick_interval_seconds' => 1,
    ]);
    $explicit = Bot::factory()->create([
        'user_id' => $user->id,
        'tick_interval_seconds' => 5,
    ]);

    $this->artisan('migrate', [
        '--path' => botTickIntervalMigrationPath(),
    ])->assertSuccessful();

    expect($oldDefault->fresh()->tick_interval_seconds)->toBe(60)
        ->and($explicit->fresh()->tick_interval_seconds)->toBe(5);
});

it('sets the bots tick interval column default to 60', function () {
    $default = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'bots')
        ->where('column_name', 'tick_interval_seconds')
        ->value('column_default');

    expect((int) $default)->toBe(60);
});
