<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function botPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'My BTC grid',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'paper',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '50000',
        'level_count' => 6,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ], $overrides);
}

it('creates a paper bot with strategy configuration and risk limits', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('bots.store'), botPayload());

    $response->assertRedirect(route('bots.index'));

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect($bot->name)->toBe('My BTC grid')
        ->and($bot->mode->value)->toBe('paper')
        ->and($bot->status->value)->toBe('idle')
        ->and($bot->quote_inventory)->toBe('1000.0000000000000000')
        ->and($bot->strategy_key)->toBe('simple-grid');

    expect(StrategyConfig::where('bot_id', $bot->id)->firstOrFail()->configuration)
        ->toMatchArray([
            'center_price' => '50000',
            'levels_per_side' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ]);

    $this->assertDatabaseHas('risk_limits', ['user_id' => $user->id, 'bot_id' => null, 'scope' => 'user']);
    $this->assertDatabaseHas('activity_logs', ['bot_id' => $bot->id, 'type' => 'bot.created']);
});

it('stores the configured default tick interval for a new bot', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('bots.store'), botPayload())->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->tick_interval_seconds)->toBe(60);
});

it('stores the configured tick interval override for a new bot', function () {
    config(['trading.tick.interval_seconds' => 5]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('bots.store'), botPayload())->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->tick_interval_seconds)->toBe(5);
});

it('defaults to paper mode when mode is omitted', function () {
    $user = User::factory()->create();
    $payload = botPayload();
    unset($payload['mode']);

    $this->actingAs($user)->post(route('bots.store'), $payload)->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->mode->value)->toBe('paper');
});

it('rejects invalid creation fields', function (string $field, mixed $value) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload([$field => $value]))
        ->assertSessionHasErrors($field);

    expect(Bot::count())->toBe(0);
})->with([
    'name' => ['name', ''],
    'symbol' => ['symbol', 'BTCUSDT'],
    'strategy' => ['strategy_key', 'unknown'],
    'capital' => ['capital_allocation', '0'],
    'center' => ['center_price', '0'],
    'levels' => ['level_count', 0],
    'spacing type' => ['spacing_type', 'fibonacci'],
    'spacing' => ['spacing_value', '0'],
    'quantity' => ['order_quantity', '0'],
    'fee' => ['fee_rate', '1'],
]);

it('requires a stored credential for live mode', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['mode' => 'live']))
        ->assertSessionHasErrors('mode');

    expect(Bot::count())->toBe(0);
});

it('creates a live bot when a credential exists', function () {
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload([
            'mode' => 'live',
            'live_confirmation' => 'My BTC grid on binance',
        ]))
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect($bot->mode->value)->toBe('live')
        ->and($bot->exchange_credential_id)->toBe($credential->id);
});

it('rejects trailing and dynamic strategies without required parameters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['strategy_key' => 'trailing-grid']))
        ->assertSessionHasErrors('trailing_distance');

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['strategy_key' => 'dynamic-grid']))
        ->assertSessionHasErrors(['min_spacing', 'max_spacing']);
});


it('rejects an exchange outside the allowlist', function () {
    config(['trading.exchanges' => ['binance', 'kraken']]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['exchange' => 'kucoin']))
        ->assertSessionHasErrors('exchange');

    expect(Bot::count())->toBe(0);
});

it('accepts an allowlisted non-default exchange', function () {
    config(['trading.exchanges' => ['binance', 'kraken']]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['exchange' => 'kraken']))
        ->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->exchange)->toBe('kraken');
});

it('offers only allowlisted exchanges on the create page', function () {
    config(['trading.exchanges' => ['binance', 'kraken']]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bots.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Create')
            ->where('exchanges', ['binance', 'kraken']));
});

it('uses the configured default exchange when none is submitted', function () {
    config(['trading.exchanges' => ['binance', 'kraken'], 'trading.default_exchange' => 'kraken']);

    $user = User::factory()->create();
    $payload = botPayload();
    unset($payload['exchange']);

    $this->actingAs($user)->post(route('bots.store'), $payload)->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->exchange)->toBe('kraken');
});

it('treats a blank exchange as the configured default', function () {
    config(['trading.exchanges' => ['binance', 'kraken'], 'trading.default_exchange' => 'kraken']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload(['exchange' => '']))
        ->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->exchange)->toBe('kraken');
});

it('rejects a default exchange that is not on the allowlist', function () {
    config(['trading.exchanges' => ['binance'], 'trading.default_exchange' => 'kraken']);

    $user = User::factory()->create();
    $payload = botPayload();
    unset($payload['exchange']);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertSessionHasErrors('exchange');

    expect(Bot::count())->toBe(0);
});
