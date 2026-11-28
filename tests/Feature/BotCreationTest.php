<?php

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Http\Requests\StoreBotRequest;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\GridPlanClient;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('derives quick-setup values from the preset on the server', function () {
    $user = User::factory()->create();
    $payload = botPayload([
        'preset_key' => 'balanced',
        // Stale client values must not win over the selected preset.
        'strategy_key' => 'martingale-grid',
        'level_count' => 1,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '1',
        'order_quantity' => '0.5',
    ]);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'))
        ->assertSessionHasNoErrors();

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect($bot->strategy_key)->toBe('simple-grid')
        ->and(StrategyConfig::where('bot_id', $bot->id)->firstOrFail()->configuration)
        ->toMatchArray([
            'levels_per_side' => 6,
            'spacing_type' => 'geometric',
            'spacing_value' => '0.004',
            'order_quantity' => '0.0026666666666667',
        ]);
});

it('accepts a dynamic grid without the shared spacing fields', function () {
    $user = User::factory()->create();
    $payload = botPayload([
        'strategy_key' => 'dynamic-grid',
        'min_spacing' => '50',
        'max_spacing' => '500',
    ]);

    unset($payload['spacing_type'], $payload['spacing_value']);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'))
        ->assertSessionHasNoErrors();

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect(StrategyConfig::where('bot_id', $bot->id)->firstOrFail()->configuration)
        ->toMatchArray([
            'spacing_type' => 'arithmetic',
            'spacing_value' => '0',
            'min_spacing' => '50',
            'max_spacing' => '500',
            'spacing_hysteresis_percent' => '20',
        ]);
});

it('stores an explicit dynamic grid spacing hysteresis', function () {
    $user = User::factory()->create();
    $payload = botPayload([
        'strategy_key' => 'dynamic-grid',
        'min_spacing' => '50',
        'max_spacing' => '500',
        'spacing_hysteresis_percent' => '12.5',
    ]);

    unset($payload['spacing_type'], $payload['spacing_value']);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'))
        ->assertSessionHasNoErrors();

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect(StrategyConfig::where('bot_id', $bot->id)->firstOrFail()->configuration)
        ->toMatchArray(['spacing_hysteresis_percent' => '12.5']);
});

it('accepts the inclusive dynamic grid spacing hysteresis bounds', function () {
    $user = User::factory()->create();

    foreach (['0', '90'] as $value) {
        $payload = botPayload([
            'strategy_key' => 'dynamic-grid',
            'min_spacing' => '50',
            'max_spacing' => '500',
            'spacing_hysteresis_percent' => $value,
        ]);

        unset($payload['spacing_type'], $payload['spacing_value']);

        $this->actingAs($user)
            ->post(route('bots.store'), $payload)
            ->assertRedirect(route('bots.index'))
            ->assertSessionHasNoErrors();
    }
});

it('rejects dynamic grid spacing hysteresis outside 0 to 90', function () {
    $user = User::factory()->create();
    $payload = botPayload([
        'strategy_key' => 'dynamic-grid',
        'min_spacing' => '50',
        'max_spacing' => '500',
        'spacing_hysteresis_percent' => '91',
    ]);

    unset($payload['spacing_type'], $payload['spacing_value']);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertSessionHasErrors('spacing_hysteresis_percent');
});

it('rejects malformed dynamic grid spacing hysteresis values', function (string $value) {
    $user = User::factory()->create();
    $payload = botPayload([
        'strategy_key' => 'dynamic-grid',
        'min_spacing' => '50',
        'max_spacing' => '500',
        'spacing_hysteresis_percent' => $value,
    ]);

    unset($payload['spacing_type'], $payload['spacing_value']);

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertSessionHasErrors('spacing_hysteresis_percent');
})->with(['.5', '20.', '1e1']);

it('stores the configured default tick interval for a new bot', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('bots.store'), botPayload())->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->tick_interval_seconds)->toBe(10);
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

it('defaults the fee rate from config when the field is omitted', function () {
    config(['trading.paper.fee_rate' => '0.0015']);
    $user = User::factory()->create();
    $payload = botPayload();
    unset($payload['fee_rate']);

    $this->actingAs($user)->post(route('bots.store'), $payload)->assertRedirect(route('bots.index'));

    expect(Bot::where('user_id', $user->id)->firstOrFail()->fee_rate)->toBe('0.00150000');
});

it('fails closed without persisting when the plan engine is unreachable', function () {
    config(['engine.plan_url' => 'http://127.0.0.1:1']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload())
        ->assertSessionHasErrors('strategy_key');

    expect(Bot::count())->toBe(0)
        ->and(StrategyConfig::count())->toBe(0);
});

it('rejects a grid that cannot be constructed', function (array $overrides, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store'), botPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Bot::count())->toBe(0)
        ->and(StrategyConfig::count())->toBe(0);
})->with([
    'geometric spacing of one' => [['spacing_type' => 'geometric', 'spacing_value' => '1'], 'spacing_value'],
    'non-positive level price' => [['center_price' => '100', 'spacing_value' => '100'], 'spacing_value'],
    'duplicate level prices' => [['spacing_value' => '0.000000000000000001'], 'spacing_value'],
]);

it('rejects an order below the exchange minimum when market data is cached', function (MarketData $market) {
    $user = User::factory()->create();

    try {
        app(MarketDataResolver::class)->prime('binance', $market);

        $this->actingAs($user)
            ->post(route('bots.store'), botPayload())
            ->assertSessionHasErrors('order_quantity');

        expect(Bot::count())->toBe(0);
    } finally {
        Cache::flush();
    }
})->with([
    'minimum amount' => [MarketData::make('BTC/USDT', '50000', minAmount: '0.01')],
    'minimum notional' => [MarketData::make('BTC/USDT', '50000', minNotional: '1000')],
]);

it('creates a bot when the plan only produces advisories', function () {
    $user = User::factory()->create();

    try {
        app(MarketDataResolver::class)->prime('binance', MarketData::make('BTC/USDT', '50000'));

        $payload = botPayload([
            'center_price' => '100000',
            'level_count' => 12,
            'order_quantity' => '0.02',
        ]);

        $this->actingAs($user)
            ->post(route('bots.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('bots.index'));

        $request = StoreBotRequest::create(route('bots.store'), 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $plan = app(GridPlanClient::class)->evaluate($request->gridPlanRequest());

        expect(array_map(fn ($problem) => $problem->code, $plan->warnings()))
            ->toContain('plan.max_open_orders', 'plan.max_position_notional', 'plan.center_price_band', 'plan.unfunded_sells')
            ->and(Bot::count())->toBe(1);
    } finally {
        Cache::flush();
    }
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
