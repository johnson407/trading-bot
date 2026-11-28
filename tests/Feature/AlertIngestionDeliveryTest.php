<?php

use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\RiskEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class);

it('queues email for a critical alert created by the engine scanner', function () {
    Queue::fake();

    RiskEvent::factory()->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
    ]);

    $this->artisan('alerts:ingest-engine-events')->assertSuccessful();

    expect(Alert::query()->count())->toBe(1);

    Queue::assertPushed(SendAlertEmail::class, 1);
});
