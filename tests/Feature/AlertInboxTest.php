<?php

use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lists only the signed-in user alerts newest first and paginates', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Owned bot']);

    Alert::factory()->for($user)->for($bot)->create([
        'title' => 'Older',
        'created_at' => now()->subMinutes(2),
    ]);
    Alert::factory()->for($user)->create([
        'title' => 'Newer',
        'created_at' => now()->subMinute(),
    ]);
    Alert::factory()->for($other)->create(['title' => 'Other user']);
    Alert::factory()->for($user)->count(49)->create([
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index')
            ->where('alerts.total', 51)
            ->where('alerts.per_page', 50)
            ->has('alerts.data', 50)
            ->where('alerts.data.0.title', 'Newer')
            ->etc());
});

it('filters alerts by an owned bot and rejects another user bot', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $owned = Bot::factory()->for($user)->create();
    $foreign = Bot::factory()->for($other)->create();

    Alert::factory()->for($user)->for($owned)->create();
    Alert::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $owned->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.bot.id', $owned->id)
            ->where('filters.bot_id', $owned->id)
            ->etc());

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $foreign->id]))
        ->assertNotFound();
});

it('renders the inbox props for an alert', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['name' => 'Grid One']);
    $alert = Alert::factory()->for($user)->for($bot)->create([
        'type' => 'risk.daily_loss_limit',
        'severity' => 'critical',
        'title' => 'Daily loss limit reached',
        'message' => 'Bot paused.',
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index')
            ->where('alerts.data.0.id', $alert->id)
            ->where('alerts.data.0.type', 'risk.daily_loss_limit')
            ->where('alerts.data.0.severity', 'critical')
            ->where('alerts.data.0.title', 'Daily loss limit reached')
            ->where('alerts.data.0.message', 'Bot paused.')
            ->where('alerts.data.0.bot.id', $bot->id)
            ->where('alerts.data.0.bot.name', 'Grid One')
            ->where('alerts.data.0.read_at', null)
            ->has('bots', 1)
            ->where('filters.bot_id', null)
            ->etc());
});

it('marks one owned alert as read and hides another user alert', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $owned = Alert::factory()->for($user)->create();
    $foreign = Alert::factory()->for($other)->create();

    $this->actingAs($user)
        ->post(route('alerts.read', $owned->id))
        ->assertRedirect();

    expect($owned->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('alerts.read', $foreign->id))
        ->assertNotFound();

    expect($foreign->fresh()->read_at)->toBeNull();
});

it('marks all visible alerts as read without touching another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Alert::factory()->for($user)->count(3)->create();
    Alert::factory()->for($user)->read()->create();
    $foreign = Alert::factory()->for($other)->create();

    $this->actingAs($user)
        ->post(route('alerts.read-all'))
        ->assertRedirect();

    expect($user->unreadAlerts()->count())->toBe(0)
        ->and($foreign->fresh()->read_at)->toBeNull();
});

it('marks all unread alerts for a filtered bot as read', function () {
    $user = User::factory()->create();
    $first = Bot::factory()->for($user)->create();
    $second = Bot::factory()->for($user)->create();

    Alert::factory()->for($user)->for($first)->count(2)->create();
    Alert::factory()->for($user)->for($second)->create();

    $this->actingAs($user)
        ->post(route('alerts.read-all'), ['bot_id' => $first->id])
        ->assertRedirect();

    expect(Alert::query()->where('bot_id', $first->id)->whereNull('read_at')->count())->toBe(0)
        ->and(Alert::query()->where('bot_id', $second->id)->whereNull('read_at')->count())->toBe(1);
});

it('shares the unread alert count with Inertia', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Alert::factory()->for($user)->count(2)->create();
    Alert::factory()->for($user)->read()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_alerts', 2)
            ->etc());

    $this->actingAs($other)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.unread_alerts', 0)
            ->etc());
});

it('links alerts with an unread badge from both navigations', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));

    expect(substr_count($layout, "route('alerts.index')"))->toBeGreaterThanOrEqual(2)
        ->and($layout)->toContain('auth.unread_alerts');
});

it('rejects a non-numeric alert id with a not-found response', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/alerts/not-a-number/read')
        ->assertNotFound();
});

it('rejects another user bot when marking all alerts as read', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = Bot::factory()->for($other)->create();

    Alert::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('alerts.read-all'), ['bot_id' => $foreign->id])
        ->assertNotFound();

    expect($user->unreadAlerts()->count())->toBe(1);
});

it('rejects a non-integer bot filter', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('alerts.index', ['bot_id' => $bot->id.'.9']))
        ->assertNotFound();
});
