<?php

use App\Domain\Bot\Enums\BotStatus;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('exposes bot counts, aggregate profit and loss, and recent activity on the dashboard', function () {
    $user = User::factory()->create();

    $running = Bot::factory()->for($user)->running()->create([
        'realized_pnl' => '10',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '120'],
    ]);

    Bot::factory()->for($user)->create([
        'status' => BotStatus::Paused,
        'realized_pnl' => '-4',
    ]);

    Bot::factory()->for($user)->create([
        'status' => BotStatus::Idle,
        'realized_pnl' => '0',
    ]);

    Bot::factory()->create(['realized_pnl' => '999']);

    ActivityLog::create([
        'user_id' => $user->id,
        'bot_id' => $running->id,
        'type' => 'bot.started',
        'severity' => 'info',
        'message' => 'Bot started',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary.total', 3)
            ->where('summary.running', 1)
            ->where('summary.paused', 1)
            ->where('summary.idle', 1)
            ->where('summary.stopped', 0)
            ->where('summary.realized_pnl', '6')
            ->where('summary.unrealized_pnl', '20')
            ->has('recentActivity', 1)
            ->where('recentActivity.0.type', 'bot.started')
            ->where('recentActivity.0.bot', $running->name)
            ->etc());
});

it('shows an empty dashboard for a user without bots', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary.total', 0)
            ->where('summary.realized_pnl', '0')
            ->where('summary.unrealized_pnl', '0')
            ->has('recentActivity', 0)
            ->etc());
});
