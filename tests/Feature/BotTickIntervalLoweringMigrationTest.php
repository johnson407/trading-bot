<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function botTickIntervalLoweringMigrationPath(): string
{
    return 'database/migrations/2026_11_28_000000_lower_default_bot_tick_interval_to_10.php';
}

it('lowers bots on the old default interval to 10 without changing explicit intervals', function () {
    $this->artisan('migrate:rollback', [
        '--path' => botTickIntervalLoweringMigrationPath(),
    ])->assertSuccessful();

    $user = User::factory()->create();
    $oldDefault = Bot::factory()->create([
        'user_id' => $user->id,
        'tick_interval_seconds' => 60,
    ]);
    $explicit = Bot::factory()->create([
        'user_id' => $user->id,
        'tick_interval_seconds' => 5,
    ]);

    $this->artisan('migrate', [
        '--path' => botTickIntervalLoweringMigrationPath(),
    ])->assertSuccessful();

    expect($oldDefault->fresh()->tick_interval_seconds)->toBe(10)
        ->and($explicit->fresh()->tick_interval_seconds)->toBe(5);
});

it('sets the bots tick interval column default to 10', function () {
    $default = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'bots')
        ->where('column_name', 'tick_interval_seconds')
        ->value('column_default');

    expect((int) $default)->toBe(10);
});
