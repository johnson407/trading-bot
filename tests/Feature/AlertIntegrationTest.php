<?php

use App\Actions\ActivateKillSwitch;
use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

it('creates one account-level kill-switch alert visible only to the owner', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $other = User::factory()->create();

    Bot::factory()->for($owner)->running()->count(2)->create();

    app(ActivateKillSwitch::class)->handle($owner);

    $alert = Alert::query()->first();

    expect(Alert::query()->count())->toBe(1)
        ->and($alert)->not->toBeNull()
        ->and($alert->bot_id)->toBeNull()
        ->and($alert->user_id)->toBe($owner->id)
        ->and($alert->type)->toBe('kill_switch.activated');

    $this->actingAs($owner)
        ->get(route('alerts.index', ['mode' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', $alert->id)
            ->etc());

    $this->actingAs($other)
        ->get(route('alerts.index', ['mode' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 0)
            ->etc());
});
