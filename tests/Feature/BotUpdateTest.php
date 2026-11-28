<?php

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Http\Requests\UpdateBotRequest;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Validator;

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

function validateBotUpdateRequest(Bot $bot, array $payload): Validator
{
    $route = new Illuminate\Routing\Route('PATCH', '/bots/{bot}', []);
    $route->bind(Request::create('/bots/'.$bot->id, 'PATCH'));
    $route->setParameter('bot', $bot);

    $request = UpdateBotRequest::create('/bots/'.$bot->id, 'PATCH', $payload);
    $request->setRouteResolver(fn () => $route);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    return Illuminate\Support\Facades\Validator::make($request->all(), $request->rules());
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

it('requires confirmation to update a running paper bot', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertSessionHasErrors('confirm_running_edit');

    expect($bot->fresh()->name)->toBe('Old name');
});

it('requires confirmation only for running paper bots', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);

    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    expect(validateBotUpdateRequest($bot, updatePayload())->errors()->has('confirm_running_edit'))->toBeTrue()
        ->and(validateBotUpdateRequest($bot, updatePayload(['confirm_running_edit' => '1']))->passes())->toBeTrue();

    $bot->forceFill(['status' => 'running', 'mode' => 'live'])->save();
    expect(validateBotUpdateRequest($bot, updatePayload())->passes())->toBeTrue();

    $bot->forceFill(['status' => 'idle', 'mode' => 'paper'])->save();
    expect(validateBotUpdateRequest($bot, updatePayload())->passes())->toBeTrue();
});

it('allows running paper edits while keeping live running and paused edits blocked', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);

    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    expect($user->can('update', $bot))->toBeTrue();

    $bot->forceFill(['status' => 'running', 'mode' => 'live'])->save();
    expect($user->can('update', $bot))->toBeFalse();

    $bot->forceFill(['status' => 'paused', 'mode' => 'paper'])->save();
    expect($user->can('update', $bot))->toBeFalse();

    $bot->forceFill(['status' => 'idle', 'mode' => 'paper'])->save();
    expect($user->can('update', $bot))->toBeTrue();

    $bot->forceFill(['status' => 'stopped', 'mode' => 'paper'])->save();
    expect($user->can('update', $bot))->toBeTrue();
});

it('forbids PATCH for live running and paused bots', function (string $status, string $mode) {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => $status, 'mode' => $mode])->save();

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertForbidden();

    expect($bot->fresh()->name)->toBe('Old name');
})->with([
    'live running' => ['running', 'live'],
    'paused paper' => ['paused', 'paper'],
]);

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
    $grid = Grid::factory()->for($bot)->create(['center_price' => '50000']);
    $level = GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '49900']);

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

it('applies a confirmed running paper edit and requests a reconcile', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload(['confirm_running_edit' => '1']))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    $bot->refresh();
    $activity = ActivityLog::query()
        ->where('bot_id', $bot->id)
        ->where('type', 'bot.updated')
        ->latest('id')
        ->firstOrFail();

    expect($bot->name)->toBe('New name')
        ->and($bot->reconcile_pending)->toBeTrue()
        ->and($bot->strategy_reset_pending)->toBeFalse()
        ->and($publisher->ofType('bot.config'))->toHaveCount(1)
        ->and($publisher->ofType('bot.config')[0]['payload'])->toBe(['strategy_reset' => true, 'reconcile' => true])
        ->and($activity->context['reconcile'])->toBeTrue()
        ->and($activity->context['strategy_reset'])->toBeTrue()
        ->and(collect($activity->context['diff'])->pluck('key'))->toContain('capital_allocation', 'strategy.center_price');
});

it('does not reconcile a running paper bot for name or fee changes', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'name' => 'New name',
            'fee_rate' => '0.001',
            'capital_allocation' => '1000',
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'confirm_running_edit' => '1',
        ]))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    $bot->refresh();

    expect($bot->name)->toBe('New name')
        ->and($bot->fee_rate)->toBe('0.00100000')
        ->and($bot->reconcile_pending)->toBeFalse()
        ->and($publisher->ofType('bot.config'))->toHaveCount(0);
});

it('treats an unchanged running paper edit as a no-op', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), [
            'name' => 'Old name',
            'capital_allocation' => '1000',
            'fee_rate' => '0.002',
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'confirm_running_edit' => '1',
        ])
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHas('status', 'No changes.');

    expect($bot->fresh()->name)->toBe('Old name')
        ->and(ActivityLog::query()->where('bot_id', $bot->id)->where('type', 'bot.updated')->count())->toBe(0)
        ->and($publisher->ofType('bot.config'))->toHaveCount(0);
});

it('reconciles a running paper bot when capital changes', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'name' => 'Old name',
            'fee_rate' => '0.002',
            'capital_allocation' => '1500',
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'confirm_running_edit' => '1',
        ]))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    $bot->refresh();

    expect($bot->capital_allocation)->toBe('1500.0000000000000000')
        ->and($bot->reconcile_pending)->toBeTrue()
        ->and($publisher->ofType('bot.config'))->toHaveCount(1)
        ->and($publisher->ofType('bot.config')[0]['payload'])->toBe(['strategy_reset' => true, 'reconcile' => true]);
});

it('rechecks running paper confirmation under the lock', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $stale = $bot->fresh();

    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    Route::bind('bot', fn ($value) => $stale);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertSessionHasErrors('confirm_running_edit');

    expect($bot->fresh()->name)->toBe('Old name');
});

it('keeps a running paper reconcile pending when the command cannot be published', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill(['status' => 'running', 'mode' => 'paper'])->save();
    $publisher = app(CommandPublisher::class);
    $publisher->fail = true;

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload(['confirm_running_edit' => '1']))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('engine');

    expect($bot->fresh()->reconcile_pending)->toBeTrue();

    $publisher->fail = false;

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload(['confirm_running_edit' => '1']))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    expect($bot->fresh()->reconcile_pending)->toBeTrue()
        ->and($publisher->ofType('bot.config'))->toHaveCount(1)
        ->and($publisher->ofType('bot.config')[0]['payload'])->toBe(['strategy_reset' => true, 'reconcile' => true]);
});

it('forces a pending reconcile even when a running paper edit is unchanged', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill([
        'status' => 'running',
        'mode' => 'paper',
        'reconcile_pending' => true,
    ])->save();
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), [
            'name' => 'Old name',
            'capital_allocation' => '1000',
            'fee_rate' => '0.002',
            'center_price' => '50000',
            'level_count' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
            'confirm_running_edit' => '1',
        ])
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    expect($publisher->ofType('bot.config'))->toHaveCount(1)
        ->and($publisher->ofType('bot.config')[0]['payload'])->toBe(['strategy_reset' => true, 'reconcile' => true]);
});

it('publishes a config command without writing engine-owned strategy or grid state when structural parameters change', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();
    $config->forceFill(['state' => ['version' => 1, 'data' => [
        'center_price' => '50000',
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'fill_count' => 3,
    ]]])->save();

    $grid = Grid::factory()->for($bot)->create([
        'center_price' => '50000',
        'status' => 'active',
    ]);
    $publisher = app(CommandPublisher::class);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot));

    expect($config->fresh()->state['data']['fill_count'])->toBe(3)
        ->and($grid->fresh()->status->value)->toBe('active')
        ->and($publisher->ofType('bot.config'))->toHaveCount(1)
        ->and($publisher->ofType('bot.config')[0]['bot_id'])->toBe($bot->id)
        ->and($publisher->ofType('bot.config')[0]['payload'])->toBe(['strategy_reset' => true]);
});

it('keeps a retryable config reset when the engine command cannot be published', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $publisher = app(CommandPublisher::class);
    $publisher->fail = true;

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasErrors('engine');

    expect($bot->fresh()->strategy_reset_pending)->toBeTrue();

    $publisher->fail = false;

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    expect($bot->fresh()->strategy_reset_pending)->toBeTrue()
        ->and($publisher->ofType('bot.config'))->toHaveCount(1);
});

it('rejects an update that cannot be constructed and leaves the bot unchanged', function (array $overrides) {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload($overrides))
        ->assertSessionHasErrors('spacing_value');

    expect($bot->fresh()->name)->toBe('Old name')
        ->and($bot->fresh()->capital_allocation)->toBe('1000.0000000000000000')
        ->and($config->fresh()->configuration)
        ->toMatchArray(['center_price' => '50000', 'spacing_value' => '100'])
        ->and($config->fresh()->state['data']['fill_count'])->toBe(3);
})->with([
    'geometric spacing of one' => [['spacing_type' => 'geometric', 'spacing_value' => '1']],
    'non-positive level price' => [['center_price' => '100', 'spacing_value' => '100']],
    'duplicate level prices' => [['spacing_value' => '0.000000000000000001']],
]);

it('rejects an update below the exchange minimum when market data is cached', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);

    app(MarketDataResolver::class)->prime(
        'binance',
        MarketData::make('BTC/USDT', '50000', null, null, 2, 6, '0.000001', '1000'),
    );

    try {
        $this->actingAs($user)
            ->patch(route('bots.update', $bot), updatePayload(['order_quantity' => '0.005']))
            ->assertSessionHasErrors('order_quantity');

        expect($bot->fresh()->name)->toBe('Old name')
            ->and($bot->fresh()->capital_allocation)->toBe('1000.0000000000000000');
    } finally {
        Cache::flush();
    }
});

it('stores dynamic grid spacing hysteresis on update', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => 'dynamic-grid',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'min_spacing' => '1',
            'max_spacing' => '10',
            'order_quantity' => '1',
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'min_spacing' => '1',
            'max_spacing' => '10',
            'spacing_hysteresis_percent' => '12.5',
        ]))
        ->assertRedirect(route('bots.show', $bot))
        ->assertSessionHasNoErrors();

    expect(StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail()->configuration)
        ->toMatchArray(['spacing_hysteresis_percent' => '12.5']);
});

it('rejects dynamic grid spacing hysteresis outside 0 to 90 on update', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => 'dynamic-grid',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'min_spacing' => '1',
            'max_spacing' => '10',
            'order_quantity' => '1',
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'min_spacing' => '1',
            'max_spacing' => '10',
            'spacing_hysteresis_percent' => '91',
        ]))
        ->assertSessionHasErrors('spacing_hysteresis_percent');
});

it('rejects malformed dynamic grid spacing hysteresis values on update', function (string $value) {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => 'dynamic-grid',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'min_spacing' => '1',
            'max_spacing' => '10',
            'order_quantity' => '1',
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'min_spacing' => '1',
            'max_spacing' => '10',
            'spacing_hysteresis_percent' => $value,
        ]))
        ->assertSessionHasErrors('spacing_hysteresis_percent');
})->with(['.5', '20.', '1e1']);

it('rejects a martingale maximum below the order quantity on update', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => 'martingale-grid',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'martingale-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'base_order_quantity' => '1',
            'max_order_quantity' => '10',
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'order_quantity' => '5',
            'max_order_quantity' => '1',
            'size_multiplier' => '2',
            'max_increases' => 3,
        ]))
        ->assertSessionHasErrors('max_order_quantity');
});

it('keeps a field error for a non-numeric martingale maximum on update', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'strategy_key' => 'martingale-grid',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'martingale-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'base_order_quantity' => '1',
            'max_order_quantity' => '10',
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload([
            'order_quantity' => '1',
            'max_order_quantity' => 'not-a-number',
            'size_multiplier' => '2',
            'max_increases' => 3,
        ]))
        ->assertSessionHasErrors('max_order_quantity');
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

it('preserves engine-owned settings when a bot is updated', function () {
    $user = User::factory()->create();
    $bot = updatableBot($user);
    $bot->forceFill([
        'settings' => [
            'mark_price' => '50123',
            'recent_prices' => ['50000', '50123'],
            'daily_loss_baseline' => '0',
            'webhook_url' => 'https://example.test/hook',
        ],
    ])->save();

    $this->actingAs($user)
        ->patch(route('bots.update', $bot), updatePayload())
        ->assertRedirect(route('bots.show', $bot));

    expect($bot->fresh()->settings)->toMatchArray([
        'mark_price' => '50123',
        'recent_prices' => ['50000', '50123'],
        'daily_loss_baseline' => '0',
        'webhook_url' => 'https://example.test/hook',
    ]);
});

it('merges user settings without overwriting engine-owned keys', function () {
    $bot = Bot::factory()->create([
        'settings' => [
            'mark_price' => '50123',
            'recent_prices' => ['50000'],
            'daily_loss_baseline' => '10',
            'webhook_url' => 'old',
        ],
    ]);

    $bot->mergeSettings([
        'mark_price' => '1',
        'recent_prices' => [],
        'daily_loss_baseline' => '99',
        'webhook_url' => 'new',
        'theme' => 'dark',
    ]);

    $bot->save();

    expect($bot->fresh()->settings)->toMatchArray([
        'mark_price' => '50123',
        'recent_prices' => ['50000'],
        'daily_loss_baseline' => '10',
        'webhook_url' => 'new',
        'theme' => 'dark',
    ]);
});
