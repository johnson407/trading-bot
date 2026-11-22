<?php

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prunes only expired alerts and preserves source records', function () {
    $user = User::factory()->create();
    $old = Alert::factory()->for($user)->create(['created_at' => now()->subDays(91)]);
    $new = Alert::factory()->for($user)->create(['created_at' => now()->subDays(10)]);
    $risk = RiskEvent::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(120)]);
    $activity = ActivityLog::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(120)]);

    $this->artisan('alerts:prune')->assertSuccessful();

    expect(Alert::query()->find($old->id))->toBeNull()
        ->and(Alert::query()->find($new->id))->not->toBeNull()
        ->and(RiskEvent::query()->find($risk->id))->not->toBeNull()
        ->and(ActivityLog::query()->find($activity->id))->not->toBeNull();
});

it('accepts a days override', function () {
    $user = User::factory()->create();
    $expired = Alert::factory()->for($user)->create(['created_at' => now()->subDays(40)]);
    $kept = Alert::factory()->for($user)->create(['created_at' => now()->subDays(10)]);

    $this->artisan('alerts:prune', ['--days' => 30])->assertSuccessful();

    expect(Alert::query()->find($expired->id))->toBeNull()
        ->and(Alert::query()->find($kept->id))->not->toBeNull();
});

it('rejects an invalid days override without deleting alerts', function () {
    $user = User::factory()->create();
    $alert = Alert::factory()->for($user)->create(['created_at' => now()->subDays(40)]);

    $this->artisan('alerts:prune', ['--days' => 'not-a-number'])
        ->assertExitCode(1);

    expect(Alert::query()->find($alert->id))->not->toBeNull();
});
