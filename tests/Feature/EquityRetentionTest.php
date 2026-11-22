<?php

use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\EquitySnapshot;
use App\Models\Fill;
use App\Models\InventoryLedgerEntry;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('prunes expired equity snapshots without touching trading history', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $expired = EquitySnapshot::factory()->for($user)->at(now()->subDays(91)->toDateTimeString())->create();
    $survivor = EquitySnapshot::factory()->for($user)->at(now()->subDays(60)->toDateTimeString())->create();
    $recent = EquitySnapshot::factory()->for($user)->at(now()->subDays(10)->toDateTimeString())->create();

    $fill = Fill::factory()->for($bot)->create(['filled_at' => now()->subDays(120)]);
    $ledger = InventoryLedgerEntry::factory()->for($bot)->create(['created_at' => now()->subDays(120)]);
    $risk = RiskEvent::factory()->for($user)->for($bot)->create(['created_at' => now()->subDays(120)]);
    $activity = ActivityLog::factory()->for($user)->for($bot)->create(['created_at' => now()->subDays(120)]);

    $this->artisan('analytics:prune-equity')->assertExitCode(0);

    expect(EquitySnapshot::query()->find($expired->id))->toBeNull()
        ->and(EquitySnapshot::query()->find($survivor->id))->not->toBeNull()
        ->and(EquitySnapshot::query()->find($recent->id))->not->toBeNull()
        ->and(Fill::query()->find($fill->id))->not->toBeNull()
        ->and(InventoryLedgerEntry::query()->find($ledger->id))->not->toBeNull()
        ->and(RiskEvent::query()->find($risk->id))->not->toBeNull()
        ->and(ActivityLog::query()->find($activity->id))->not->toBeNull();
});

it('accepts a retention override in days', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $expired = EquitySnapshot::factory()->for($user)->at(now()->subDays(40)->toDateTimeString())->create();
    $recent = EquitySnapshot::factory()->for($user)->at(now()->subDays(10)->toDateTimeString())->create();

    $this->artisan('analytics:prune-equity', ['--days' => '30'])->assertExitCode(0);

    expect(EquitySnapshot::query()->find($expired->id))->toBeNull()
        ->and(EquitySnapshot::query()->find($recent->id))->not->toBeNull();
});

it('rejects zero or negative retention without deleting snapshots', function (string $days) {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $snapshot = EquitySnapshot::factory()->for($user)->at(now()->subDays(10)->toDateTimeString())->create();

    $this->artisan('analytics:prune-equity', ['--days' => $days])
        ->expectsOutputToContain('positive integer')
        ->assertExitCode(1);

    expect(EquitySnapshot::query()->find($snapshot->id))->not->toBeNull();
})->with(['0', '-1']);

it('rejects a non-numeric retention value without deleting snapshots', function () {
    $this->travelTo(Carbon::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $snapshot = EquitySnapshot::factory()->for($user)->at(now()->subDays(10)->toDateTimeString())->create();

    $this->artisan('analytics:prune-equity', ['--days' => 'not-a-number'])
        ->expectsOutputToContain('positive integer')
        ->assertExitCode(1);

    expect(EquitySnapshot::query()->find($snapshot->id))->not->toBeNull();
});
