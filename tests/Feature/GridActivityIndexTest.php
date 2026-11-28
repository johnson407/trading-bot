<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function gridActivityIndexMigrationPath(): string
{
    return 'database/migrations/2026_11_28_000100_add_bot_closed_at_index_to_orders_table.php';
}

it('indexes orders by bot and closed_at for grid activity windows', function () {
    $hasIndex = fn (): bool => DB::table('pg_indexes')
        ->where('tablename', 'orders')
        ->where('indexname', 'orders_bot_id_closed_at_index')
        ->exists();

    expect($hasIndex())->toBeTrue();

    $this->artisan('migrate:rollback', ['--path' => gridActivityIndexMigrationPath()])
        ->assertSuccessful();

    expect($hasIndex())->toBeFalse();

    $this->artisan('migrate', ['--path' => gridActivityIndexMigrationPath()])
        ->assertSuccessful();

    expect($hasIndex())->toBeTrue();
});
