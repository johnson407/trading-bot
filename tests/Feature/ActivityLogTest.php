<?php

use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows bot activity and risk events newest first', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'message' => 'older activity',
        'created_at' => now()->subMinutes(10),
    ]);
    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'message' => 'newer activity',
        'created_at' => now()->subMinute(),
    ]);
    RiskEvent::factory()->create([
        'user_id' => $user->id,
        'bot_id' => $bot->id,
        'type' => 'risk.position_limit',
        'message' => 'position limit',
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)->get(route('bots.activity', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Activity')
            ->where('bot.id', $bot->id)
            ->has('activityLogs', 2)
            ->where('activityLogs.0.message', 'newer activity')
            ->where('activityLogs.1.message', 'older activity')
            ->has('riskEvents', 1)
            ->where('riskEvents.0.type', 'risk.position_limit')
            ->etc());
});

it('does not expose another users bot activity', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();
    ActivityLog::factory()->create([
        'user_id' => $owner->id,
        'bot_id' => $bot->id,
        'message' => 'private activity',
    ]);

    $this->actingAs($other)->get(route('bots.activity', $bot))->assertForbidden();
});
