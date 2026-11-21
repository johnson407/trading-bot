<?php

use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lists credentials with masked keys only', function () {
    $user = User::factory()->create();
    ExchangeCredential::factory()->for($user)->create([
        'api_key' => 'abcd1234efgh5678',
        'api_secret' => 'super-secret-value',
    ]);

    $response = $this->actingAs($user)->get(route('exchange-credentials.index'));
    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ExchangeCredentials/Index')
            ->has('credentials', 1)
            ->where('credentials.0.masked_key', 'abcd****5678')
            ->missing('credentials.0.api_secret')
            ->missing('credentials.0.api_key')
            ->etc());

    expect($response->getContent())->not->toContain('super-secret-value');
});

it('creates and replaces a credential without duplicating rows', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('exchange-credentials.store'), [
        'exchange' => 'binance',
        'api_key' => 'first-key-1234',
        'api_secret' => 'first-secret',
        'testnet' => true,
    ])->assertRedirect(route('exchange-credentials.index'));

    $credential = ExchangeCredential::firstOrFail();

    $this->actingAs($user)->put(route('exchange-credentials.update', $credential), [
        'exchange' => 'binance',
        'api_key' => 'second-key-5678',
        'api_secret' => 'second-secret',
        'testnet' => false,
    ])->assertRedirect(route('exchange-credentials.index'));

    expect(ExchangeCredential::count())->toBe(1)
        ->and($credential->fresh()->api_key)->toBe('second-key-5678')
        ->and($credential->fresh()->testnet)->toBeFalse();
});

it('deletes a credential', function () {
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('exchange-credentials.destroy', $credential))
        ->assertRedirect(route('exchange-credentials.index'));

    expect(ExchangeCredential::count())->toBe(0);
});

it('denies another user access to a credential', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($owner)->create();

    $this->actingAs($other)->put(route('exchange-credentials.update', $credential), [
        'exchange' => 'binance',
        'api_key' => 'attacker-key',
        'api_secret' => 'attacker-secret',
        'testnet' => true,
    ])->assertForbidden();

    expect($credential->fresh()->api_key)->not->toBe('attacker-key');
});
