<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function livePayload(User $user, array $overrides = []): array
{
    if (! $user->exchangeCredentials()->where('exchange', 'binance')->exists()) {
        ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);
    }

    return array_replace([
        'name' => 'Live BTC grid',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'live',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '50000',
        'level_count' => 6,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ], $overrides);
}

it('rejects a live bot without the exact confirmation phrase', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), livePayload($user))
        ->assertSessionHasErrors('live_confirmation');

    $this->actingAs($user)
        ->post(route('bots.store'), livePayload($user, ['live_confirmation' => 'Live BTC grid']))
        ->assertSessionHasErrors('live_confirmation');

    expect(Bot::query()->count())->toBe(0);
});

it('creates a live bot when the confirmation phrase matches the name and exchange', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), livePayload($user, ['live_confirmation' => 'Live BTC grid on binance']))
        ->assertRedirect(route('bots.index'))
        ->assertSessionHasNoErrors();

    $bot = Bot::query()->firstOrFail();

    expect($bot->mode->value)->toBe('live')
        ->and($bot->exchange)->toBe('binance')
        ->and($bot->status->value)->toBe('idle');
});

it('does not require confirmation for a paper bot', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), livePayload($user, ['mode' => 'paper']))
        ->assertRedirect(route('bots.index'))
        ->assertSessionHasNoErrors();

    expect(Bot::query()->count())->toBe(1);
});
