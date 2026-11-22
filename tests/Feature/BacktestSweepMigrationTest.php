<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function backtestSweepMigrationPath(): string
{
    return 'database/migrations/2026_11_22_000500_create_backtest_sweeps_table.php';
}

it('adds the sweep table and a nullable indexed sweep link on runs', function () {
    expect(Schema::hasTable('backtest_sweeps'))->toBeTrue()
        ->and(Schema::hasColumns('backtest_sweeps', [
            'id',
            'user_id',
            'bot_id',
            'name',
            'symbol',
            'timeframe',
            'start_at',
            'end_at',
            'parameters',
            'total_runs',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('backtest_runs', 'sweep_id'))->toBeTrue();

    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'backtest_runs')
        ->pluck('indexdef');

    expect($indexes->contains(fn (string $definition) => str_contains($definition, 'sweep_id')))->toBeTrue();

    $this->artisan('migrate:rollback', ['--path' => backtestSweepMigrationPath()])
        ->assertSuccessful();

    expect(Schema::hasTable('backtest_sweeps'))->toBeFalse()
        ->and(Schema::hasColumn('backtest_runs', 'sweep_id'))->toBeFalse();

    $this->artisan('migrate', ['--path' => backtestSweepMigrationPath()])
        ->assertSuccessful();

    expect(Schema::hasTable('backtest_sweeps'))->toBeTrue()
        ->and(Schema::hasColumn('backtest_runs', 'sweep_id'))->toBeTrue();
});
