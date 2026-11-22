<?php

use App\Models\EquitySnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores captured equity values with casts and an owner relation', function () {
    $user = User::factory()->create();

    $snapshot = EquitySnapshot::factory()->for($user)->create([
        'captured_at' => '2026-11-22 12:05:00',
        'equity' => '12500.50000000',
        'quote_value' => '10000.25000000',
        'base_value' => '2500.25000000',
        'realized_pnl' => '150.50000000',
        'unrealized_pnl' => '350.00000000',
        'fees_paid' => '12.25000000',
        'bot_count' => 3,
    ]);

    $fresh = $snapshot->fresh();

    expect($fresh->user->is($user))->toBeTrue()
        ->and($fresh->captured_at->toDateTimeString())->toBe('2026-11-22 12:05:00')
        ->and($fresh->equity)->toBe('12500.50000000')
        ->and($fresh->quote_value)->toBe('10000.25000000')
        ->and($fresh->base_value)->toBe('2500.25000000')
        ->and($fresh->realized_pnl)->toBe('150.50000000')
        ->and($fresh->unrealized_pnl)->toBe('350.00000000')
        ->and($fresh->fees_paid)->toBe('12.25000000')
        ->and($fresh->bot_count)->toBe(3)
        ->and($fresh->created_at)->not->toBeNull();
});

it('allows only one snapshot per owner and capture time', function () {
    $user = User::factory()->create();

    EquitySnapshot::factory()->for($user)->create([
        'captured_at' => '2026-11-22 12:05:00',
    ]);

    expect(fn () => EquitySnapshot::factory()->for($user)->create([
        'captured_at' => '2026-11-22 12:05:00',
    ]))->toThrow(QueryException::class);
});

it('provides a factory state for a specific capture time', function () {
    $snapshot = EquitySnapshot::factory()->at('2026-11-20 08:30:00')->create();

    expect($snapshot->captured_at->toDateTimeString())->toBe('2026-11-20 08:30:00');
});

it('rolls the equity snapshots migration down and back up', function () {
    $migration = require database_path('migrations/2026_11_22_000400_create_equity_snapshots_table.php');

    $migration->down();

    expect(Schema::hasTable('equity_snapshots'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('equity_snapshots'))->toBeTrue();
});
