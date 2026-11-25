<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the landing page for guests without framework version props', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('canLogin', true)
            ->where('canRegister', true)
            ->missing('laravelVersion')
            ->missing('phpVersion'));
});

it('redirects authenticated visitors from the landing page to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
