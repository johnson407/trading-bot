<?php

use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an alert with casts and owner and bot relationships', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $alert = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'title' => 'Daily loss limit reached',
        'message' => 'Bot paused after breaching the daily loss limit.',
        'context' => ['loss' => '150', 'limit' => '100'],
        'read_at' => null,
    ]);

    $fresh = $alert->fresh();

    expect($fresh->user->is($user))->toBeTrue()
        ->and($fresh->bot->is($bot))->toBeTrue()
        ->and($fresh->context)->toBe(['loss' => '150', 'limit' => '100'])
        ->and($fresh->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($fresh->read_at)->toBeNull();
});

it('scopes unread alerts', function () {
    $user = User::factory()->create();

    Alert::factory()->for($user)->create();
    Alert::factory()->for($user)->read()->create();

    expect(Alert::query()->unread()->count())->toBe(1);
});
