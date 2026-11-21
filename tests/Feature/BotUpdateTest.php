<?php

use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function updatableBot(User $user): Bot
{
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'name' => 'Old name',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'mode' => 'paper',
        'strategy_key' => 'simple-grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.002',
        'quote_inventory' => '1000',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'simple-grid',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
        'state' => ['version' => 1, 'data' => ['fill_count' => 3]],
    ]);

    return $bot;
}

function updatePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'New name',
        'capital_allocation' => '2000',
        'fee_rate' => '0.001',
        'center_price' => '60000',
        'level_count' => 4,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '250',
        'order_quantity' => '0.002',
    ], $overrides);
}

it('updates the editable fields and strategy configuration', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    $bot->refresh();
    $configuration = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail()->configuration;

    expect($bot->name)->toBe('New name')
        ->and($bot->capital_allocation)->toBe('2000.0000000000000000')
        ->and($bot->fee_rate)->toBe('0.00100000')
        ->and($configuration['center_price'])->toBe('60000')
        ->and($configuration['levels_per_side'])->toBe(4)
        ->and($configuration['spacing_value'])->toBe('250')
        ->and($configuration['order_quantity'])->toBe('0.002');

    $this->assertDatabaseHas('activity_logs', ['bot_id' => $bot->id, 'type' => 'bot.updated']);
});

it('ignores attempts to change identity fields', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'symbol' => 'ETH/USDT',
            'exchange' => 'kraken',
            'mode' => 'live',
            'strategy_key' => 'martingale-grid',
        ]))
        ->assertRedirect(route('bots.show', $bot));

    $bot->refresh();

    expect($bot->symbol)->toBe('BTC/USDT')
        ->and($bot->exchange)->toBe('binance')
        ->and($bot->mode->value)->toBe('paper')
        ->and($bot->strategy_key)->toBe('simple-grid');
});

it('rejects an update for a running bot', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running'])->save();

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertForbidden();

    expect($bot->fresh()->name)->toBe('Old name');
});

it('rejects an update from another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = updatableBot($owner);

    $this->actingAs($other)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertForbidden();

    expect($bot->fresh()->name)->toBe('Old name');
});

it('preserves the grid, strategy state, fee and identity when only non-structural fields change', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $grid = \App\Models\Grid::factory()->for($bot)->create(['center_price' => '50000']);
    $level = \App\Models\GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '49900']);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), [
            'name' => 'Renamed',
            'capital_allocation' => '1500',
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ])
        ->assertRedirect(route('bots.show', $bot));

    $bot->refresh();
    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();
    $grid->refresh();
    $level->refresh();

    expect($config->state['data']['fill_count'] ?? null)->toBe(3)
        ->and($grid->status->value)->toBe('active')
        ->and($grid->levels()->count())->toBe(1)
        ->and($level->price)->toBe('49900.0000000000000000')
        ->and($bot->fee_rate)->toBe('0.00200000')
        ->and($bot->exchange)->toBe('binance')
        ->and($bot->symbol)->toBe('BTC/USDT')
        ->and($bot->mode->value)->toBe('paper')
        ->and($bot->strategy_key)->toBe('simple-grid');
});

it('resets strategy state and retires the grid when structural parameters change', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();
    $config->forceFill(['state' => ['version' => 1, 'data' => [
        'center_price' => '50000',
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'fill_count' => 3,
    ]]])->save();

    $grid = \App\Models\Grid::factory()->for($bot)->create([
        'center_price' => '50000',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot));

    expect($config->fresh()->state)->toBeNull()
        ->and($grid->fresh()->status->value)->toBe('stopped');
});

it('applies edited structural parameters on the next tick', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'stopped'])->save();

    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();
    $config->forceFill(['state' => ['version' => 1, 'data' => [
        'center_price' => '50000',
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'fill_count' => 3,
    ]]])->save();

    \App\Models\Grid::factory()->for($bot)->create(['center_price' => '50000', 'status' => 'active']);

    $fake = new \Tests\Fixtures\FakeExchangeClient(price: '195');
    app()->instance(\App\Services\Exchange\ExchangeClientFactory::class, new \App\Services\Exchange\ExchangeClientFactory($fake, $fake));

    $this->actingAs($user)->patch(route('bots.update', $bot), [
        'name' => 'Edited grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '200',
        'level_count' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '20',
        'order_quantity' => '1',
    ])->assertRedirect(route('bots.show', $bot));

    app(\App\Actions\StartBot::class)->handle($bot->fresh());
    app(\App\Domain\Bot\BotTickPipeline::class)->tick($bot->fresh());

    $grid = $bot->fresh()->activeGrid;
    $prices = $grid->levels->sortBy('sequence')->pluck('price')->all();

    expect($grid->center_price)->toBe('200.0000000000000000')
        ->and($prices)->toBe([
            '160.0000000000000000',
            '180.0000000000000000',
            '220.0000000000000000',
            '240.0000000000000000',
        ]);
});

it('rejects missing strategy fields with validation errors instead of a server error', function (string $strategy, string $field, array $configuration) {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => $strategy,
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => $strategy,
        'configuration' => $configuration,
    ]);

    $payload = array_merge(updatePayload(), [$field => '']);
    $original = $bot->name;

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), $payload)
        ->assertSessionHasErrors($field);

    expect($bot->fresh()->name)->toBe($original);
})->with([
    'trailing' => ['trailing-grid', 'trailing_distance', [
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'trailing_distance' => '5',
    ]],
    'martingale' => ['martingale-grid', 'max_order_quantity', [
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'base_order_quantity' => '1',
        'max_order_quantity' => '10',
    ]],
    'dynamic' => ['dynamic-grid', 'min_spacing', [
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'min_spacing' => '1',
        'max_spacing' => '10',
    ]],
]);
