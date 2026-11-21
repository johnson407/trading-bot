<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

it('throttles bot control actions per user', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    RateLimiter::clear((string) $user->id);

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)->post(route('bots.start', $bot))->assertRedirect();
    }

    $this->actingAs($user)->post(route('bots.start', $bot))->assertStatus(429);
});

it('throttles kill-switch attempts per user', function () {
    $user = User::factory()->create();
    RateLimiter::clear((string) $user->id);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->post(route('kill-switch.store'), ['confirmation' => 'wrong'])->assertRedirect();
    }

    $this->actingAs($user)->post(route('kill-switch.store'), ['confirmation' => 'wrong'])->assertStatus(429);
});

it('throttles credential mutations per user', function () {
    $user = User::factory()->create();
    RateLimiter::clear((string) $user->id);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)->post(route('exchange-credentials.store'), [
            'exchange' => 'binance',
            'api_key' => 'key-'.$i,
            'api_secret' => 'secret-'.$i,
            'testnet' => true,
        ])->assertRedirect();
    }

    $this->actingAs($user)->post(route('exchange-credentials.store'), [
        'exchange' => 'binance',
        'api_key' => 'final',
        'api_secret' => 'final',
        'testnet' => true,
    ])->assertStatus(429);
});
