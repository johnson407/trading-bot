<?php

use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the grouped inbox by default', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Grid One']);

    Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'severity' => 'warning',
        'message' => 'First block',
        'created_at' => now()->subMinutes(2),
    ]);
    Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'severity' => 'critical',
        'message' => 'Latest block',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index')
            ->where('mode', 'grouped')
            ->where('groups.total', 1)
            ->where('groups.data.0.key', 'risk.position_limit|'.$bot->id)
            ->where('groups.data.0.type', 'risk.position_limit')
            ->where('groups.data.0.bot.id', $bot->id)
            ->where('groups.data.0.bot.name', 'Grid One')
            ->where('groups.data.0.total_count', 2)
            ->where('groups.data.0.unread_count', 2)
            ->where('groups.data.0.highest_severity', 'critical')
            ->where('groups.data.0.latest_message', 'Latest block')
            ->has('groups.data.0.latest_at')
            ->has('groups.data.0.through')
            ->has('recentOccurrences')
            ->etc());
});

it('keeps the ungrouped table available in all mode', function () {
    $user = User::factory()->create();

    Alert::factory()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->get(route('alerts.index', ['mode' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'all')
            ->where('alerts.total', 3)
            ->has('alerts.data', 3)
            ->where('groups', null)
            ->etc());
});

it('applies the bot filter in grouped and all modes', function () {
    $user = User::factory()->create();
    $owned = Bot::factory()->for($user)->create();
    $other = Bot::factory()->for($user)->create();

    Alert::factory()->for($user)->for($owned)->create();
    Alert::factory()->for($user)->for($other)->create();

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $owned->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.bot_id', $owned->id)
            ->where('groups.total', 1)
            ->where('groups.data.0.bot.id', $owned->id)
            ->etc());

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $owned->id, 'mode' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.bot_id', $owned->id)
            ->where('alerts.total', 1)
            ->where('alerts.data.0.bot.id', $owned->id)
            ->etc());
});

it('marks only a groups unread alerts through its latest timestamp', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $old = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'created_at' => now()->subMinutes(2),
    ]);
    $through = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'created_at' => now()->subMinute(),
    ]);
    $later = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'created_at' => now(),
    ]);
    $foreign = Alert::factory()->for($other)->create([
        'type' => 'risk.position_limit',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->post(route('alerts.groups.read'), [
            'type' => 'risk.position_limit',
            'bot_id' => $bot->id,
            'through' => $through->created_at->toIso8601String(),
        ])
        ->assertRedirect();

    expect($old->fresh()->read_at)->not->toBeNull()
        ->and($through->fresh()->read_at)->not->toBeNull()
        ->and($later->fresh()->read_at)->toBeNull()
        ->and($foreign->fresh()->read_at)->toBeNull();
});

it('marks an account-level group read', function () {
    $user = User::factory()->create();

    $alert = Alert::factory()->for($user)->create([
        'type' => 'risk.exchange_failure',
        'bot_id' => null,
        'created_at' => now()->subMinute(),
    ]);
    $later = Alert::factory()->for($user)->create([
        'type' => 'risk.exchange_failure',
        'bot_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('alerts.groups.read'), [
            'type' => 'risk.exchange_failure',
            'bot_id' => null,
            'through' => $alert->created_at->toIso8601String(),
        ])
        ->assertRedirect();

    expect($alert->fresh()->read_at)->not->toBeNull()
        ->and($later->fresh()->read_at)->toBeNull();
});

it('rejects another user bot when marking a group read', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = Bot::factory()->for($other)->create();
    $alert = Alert::factory()->for($user)->for($foreign)->create([
        'type' => 'risk.position_limit',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->post(route('alerts.groups.read'), [
            'type' => 'risk.position_limit',
            'bot_id' => $foreign->id,
            'through' => $alert->created_at->toIso8601String(),
        ])
        ->assertNotFound();

    expect($alert->fresh()->read_at)->toBeNull();
});

it('allows marking an archived bot group read', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $bot->delete();
    $alert = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.position_limit',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->post(route('alerts.groups.read'), [
            'type' => 'risk.position_limit',
            'bot_id' => $bot->id,
            'through' => $alert->created_at->toIso8601String(),
        ])
        ->assertRedirect();

    expect($alert->fresh()->read_at)->not->toBeNull();
});

it('does not show another user alerts in grouped mode', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Alert::factory()->for($other)->create();

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.total', 0)
            ->has('groups.data', 0)
            ->has('recentOccurrences', 0)
            ->etc());
});

it('wires the grouped and all views in the alerts page', function () {
    $source = file_get_contents(resource_path('js/Pages/Alerts/Index.vue'));

    expect($source)->toContain("route('alerts.groups.read')")
        ->and($source)->toContain('recentOccurrences')
        ->and($source)->toContain('groups.data')
        ->and($source)->toContain('alerts.data')
        ->and($source)->toContain('Mark group read')
        ->and($source)->toContain('View all')
        ->and($source)->toContain('Grouped')
        ->and($source)->toContain('All alerts')
        ->and($source)->toContain("mode === 'grouped'")
        ->and($source)->toContain('!group.bot?.archived')
        ->and($source)->toContain("filters.bot_id ? 'for this bot '");
});

it('renders an empty grouped inbox without hiding all alerts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'grouped')
            ->where('groups.total', 0)
            ->has('groups.data', 0)
            ->has('recentOccurrences', 0)
            ->etc());

    $this->actingAs($user)
        ->get(route('alerts.index', ['mode' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'all')
            ->where('alerts.total', 0)
            ->etc());
});
