<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the settings hub', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->where('tickIntervalSeconds', 10)
            ->etc());
});

it('shows the effective default tick interval as a read-only readout', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tickIntervalSeconds', 10)
            ->etc());

    $hub = file_get_contents(resource_path('js/Pages/Settings/Index.vue'));

    expect($hub)->toContain('Default tick interval')
        ->and($hub)->toContain('tickIntervalSeconds')
        ->and($hub)->not->toContain('<form');
});

it('floors a non-positive configured default tick interval', function () {
    config(['trading.tick.interval_seconds' => 0]);
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tickIntervalSeconds', 1)
            ->etc());
});

it('links credentials and risk limits from the settings hub', function () {
    $hub = file_get_contents(resource_path('js/Pages/Settings/Index.vue'));

    expect($hub)->toContain("route('exchange-credentials.index')")
        ->and($hub)->toContain("route('risk-limits.edit')");
});

it('serves credentials and risk limits under settings', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    expect(route('exchange-credentials.index', absolute: false))->toBe('/settings/credentials')
        ->and(route('risk-limits.edit', absolute: false))->toBe('/settings/risk-limits');

    $this->actingAs($user)->get(route('exchange-credentials.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ExchangeCredentials/Index')
            ->has('credentials')
            ->etc());

    $this->actingAs($user)->get(route('risk-limits.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RiskLimits/Edit')
            ->has('limits')
            ->etc());
});

it('redirects guests from the settings pages', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
    $this->get(route('exchange-credentials.index'))->assertRedirect(route('login'));
    $this->get(route('risk-limits.edit'))->assertRedirect(route('login'));
});
