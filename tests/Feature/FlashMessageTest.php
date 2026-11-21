<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares the success flash message with Inertia pages', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['status' => 'idle']);

    $response = $this->from(route('bots.index'))
        ->actingAs($user)
        ->post(route('bots.start', $bot));

    $response->assertRedirect(route('bots.index'));

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Index')
            ->where('flash.status', 'Bot started.')
            ->etc()
        );
});

it('shares no flash message when the session has none', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.status', null)
            ->etc()
        );
});
