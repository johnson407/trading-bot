<?php

use App\Actions\CreateBot;
use App\Domain\Backtest\BacktestComparison;
use App\Domain\Risk\RiskLimitService;
use App\Models\ActivityLog;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\User;
use App\Services\Backtest\BacktestBotPrefill;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;
use Tests\Support\FakeCommandPublisher;

uses(RefreshDatabase::class);

function sourceBotMarket(): FakeExchangeClient
{
    $fake = new FakeExchangeClient(price: '50000');

    app()->instance(
        ExchangeClientFactory::class,
        new ExchangeClientFactory(overrides: ['binance' => $fake]),
    );

    return $fake;
}

function tamperedSourcePayload(BacktestRun $run): array
{
    return [
        'source_backtest_run_id' => $run->id,
        'name' => 'Tampered',
        'exchange' => 'kraken',
        'symbol' => 'ETH/USDT',
        'strategy_key' => 'dynamic-grid',
        'mode' => 'paper',
        'capital_allocation' => '999999',
        'fee_rate' => '0.05',
        'center_price' => '1',
        'level_count' => 50,
        'spacing_type' => 'geometric',
        'spacing_value' => '0.1',
        'order_quantity' => '99',
        'preset_key' => 'balanced',
        'bot_id' => 999,
        'live_confirmation' => 'whatever',
    ];
}

it('previews source-derived values and ignores tampered source fields', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Momentum']);
    sourceBotMarket();

    $response = $this->actingAs($user)->postJson(
        route('bots.preview'),
        tamperedSourcePayload($run),
    );

    $response->assertOk()
        ->assertJsonStructure(['plan', 'limits', 'market', 'values'])
        ->assertJsonPath('values.strategy_key', 'simple-grid')
        ->assertJsonPath('values.level_count', 2)
        ->assertJsonPath('values.spacing_type', 'arithmetic')
        ->assertJsonPath('values.spacing_value', '1')
        ->assertJsonPath('values.order_quantity', '1');
});

it('stores a paper bot with an editable name and source-derived settings', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Momentum']);
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), tamperedSourcePayload($run))
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('user_id', $user->id)->firstOrFail();
    $configuration = $bot->strategyConfig->configuration;
    $activity = ActivityLog::where('bot_id', $bot->id)
        ->where('type', 'bot.created')
        ->firstOrFail();

    expect($bot->name)->toBe('Tampered')
        ->and($bot->mode->value)->toBe('paper')
        ->and($bot->exchange)->toBe('binance')
        ->and($bot->symbol)->toBe('BTC/USDT')
        ->and($bot->strategy_key)->toBe('simple-grid')
        ->and($bot->capital_allocation)->toBe('1000.0000000000000000')
        ->and($bot->fee_rate)->toBe('0.00100000')
        ->and($bot->source_backtest_run_id)->toBe($run->id)
        ->and($bot->stop_loss_percent)->toBe('4.0000')
        ->and($bot->take_profit_percent)->toBe('9.0000')
        ->and($bot->range_low)->toBe('80000.00000000')
        ->and($bot->range_high)->toBe('120000.00000000')
        ->and($configuration['center_price'])->toBe('100')
        ->and($configuration['levels_per_side'])->toBe(2)
        ->and($configuration['spacing_type'])->toBe('arithmetic')
        ->and($configuration['spacing_value'])->toBe('1')
        ->and($configuration['order_quantity'])->toBe('1')
        ->and($activity->context['source_backtest_run_id'])->toBe($run->id)
        ->and(app(FakeCommandPublisher::class)->ofType('bot.start'))->toBe([]);
});

it('returns not found for another user source run', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $run = BacktestRun::factory()->for($other)->completedSource()->create();
    sourceBotMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), ['source_backtest_run_id' => $run->id])
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('bots.store'), ['source_backtest_run_id' => $run->id])
        ->assertNotFound();

    expect(Bot::where('user_id', $user->id)->count())->toBe(0);
});

it('rejects an incomplete source run', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->create();
    sourceBotMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), ['source_backtest_run_id' => $run->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_backtest_run_id');
});

it('rejects a source run deleted while the form is open', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    $runId = $run->id;
    $run->delete();
    sourceBotMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), ['source_backtest_run_id' => $runId])
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('bots.store'), ['source_backtest_run_id' => $runId])
        ->assertNotFound();

    expect(Bot::where('user_id', $user->id)->count())->toBe(0);
});

it('uses the same derived values for preview and store', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Parity']);
    sourceBotMarket();

    $payload = ['source_backtest_run_id' => $run->id];

    $preview = $this->actingAs($user)
        ->postJson(route('bots.preview'), $payload)
        ->assertOk()
        ->json('values');

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('user_id', $user->id)->firstOrFail();

    expect($bot->name)->toBe('Parity paper')
        ->and($preview['strategy_key'])->toBe($bot->strategy_key)
        ->and($preview['level_count'])->toBe($bot->strategyConfig->configuration['levels_per_side'])
        ->and($preview['spacing_type'])->toBe($bot->strategyConfig->configuration['spacing_type'])
        ->and($preview['spacing_value'])->toBe($bot->strategyConfig->configuration['spacing_value'])
        ->and($preview['order_quantity'])->toBe($bot->strategyConfig->configuration['order_quantity']);
});

it('rolls back source-backed bot creation when persistence fails', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    $data = app(BacktestBotPrefill::class)->fromRun($run);
    $data['configuration'] = $run->config_snapshot['strategy']['configuration'];

    $risk = Mockery::mock(RiskLimitService::class);
    $risk->shouldReceive('forUser')->once()->andThrow(new RuntimeException('risk limits unavailable'));
    app()->instance(RiskLimitService::class, $risk);

    expect(fn () => app(CreateBot::class)->handle($user, $data))
        ->toThrow(RuntimeException::class, 'risk limits unavailable');

    expect(Bot::where('user_id', $user->id)->count())->toBe(0);
});

it('fails closed when the engine is unavailable', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    sourceBotMarket();
    config(['engine.plan_url' => 'http://127.0.0.1:1']);

    $this->actingAs($user)
        ->postJson(route('bots.preview'), ['source_backtest_run_id' => $run->id])
        ->assertStatus(503);

    $this->actingAs($user)
        ->post(route('bots.store'), ['source_backtest_run_id' => $run->id])
        ->assertSessionHasErrors('strategy_key');

    expect(Bot::where('user_id', $user->id)->count())->toBe(0);
});

it('allows multiple bots from the same source run', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    sourceBotMarket();

    $payload = ['source_backtest_run_id' => $run->id];

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'));

    $this->actingAs($user)
        ->post(route('bots.store'), $payload)
        ->assertRedirect(route('bots.index'));

    expect(Bot::where('source_backtest_run_id', $run->id)->count())->toBe(2);
});

it('creates a bot after the source bot was deleted', function () {
    $user = User::factory()->create();
    $sourceBot = Bot::factory()->for($user)->create();
    $run = BacktestRun::factory()->for($user)->forBot($sourceBot)->completedSource()->create();
    $sourceBot->delete();
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), ['source_backtest_run_id' => $run->id])
        ->assertRedirect(route('bots.index'));

    expect(Bot::where('source_backtest_run_id', $run->id)->count())->toBe(1);
});

it('rejects live mode for source-backed creation', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), [
            'source_backtest_run_id' => $run->id,
            'mode' => 'live',
            'live_confirmation' => 'whatever',
        ])
        ->assertSessionHasErrors('mode');

    expect(Bot::where('user_id', $user->id)->count())->toBe(0);
});

it('does not accept browser-supplied exits when the snapshot has none', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completed()->create(['name' => 'No exits']);
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), [
            'source_backtest_run_id' => $run->id,
            'name' => 'Custom exits',
            'stop_loss_percent' => '5',
            'take_profit_percent' => '10',
            'range_low' => '1',
            'range_high' => '2',
        ])
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('source_backtest_run_id', $run->id)->firstOrFail();

    expect($bot->name)->toBe('Custom exits')
        ->and($bot->stop_loss_percent)->toBeNull()
        ->and($bot->take_profit_percent)->toBeNull()
        ->and($bot->range_low)->toBeNull()
        ->and($bot->range_high)->toBeNull();
});

it('keeps dynamic-grid spacing out of the saved configuration', function () {
    $user = User::factory()->create();
    $base = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Dynamic']);
    $snapshot = $base->config_snapshot;
    $snapshot['bot']['strategy_key'] = 'dynamic-grid';
    $snapshot['strategy'] = [
        'key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '0',
            'order_quantity' => '0.001',
            'min_spacing' => '100',
            'max_spacing' => '200',
            'volatility_multiplier' => '1',
            'recalculation_interval_seconds' => 60,
        ],
    ];

    $run = BacktestRun::factory()->for($user)->completedSource()->create([
        'name' => 'Dynamic',
        'strategy_key' => 'dynamic-grid',
        'config_snapshot' => $snapshot,
    ]);
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), [
            'source_backtest_run_id' => $run->id,
            'spacing_value' => '100',
        ])
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('source_backtest_run_id', $run->id)->firstOrFail();

    expect($bot->strategyConfig->configuration['spacing_value'])->toBe('0')
        ->and(BacktestComparison::make($bot, $run, '0', 0)->toArray()['modified'])->toBeFalse();
});

it('does not mark sweep-derived bots as modified', function () {
    $user = User::factory()->create();
    $base = BacktestRun::factory()->for($user)->completedSource()->create(['name' => 'Sweep']);
    $snapshot = $base->config_snapshot;
    $snapshot['strategy']['configuration']['level_count'] = 3;
    $snapshot['strategy']['configuration']['levels_per_side'] = 3;

    $run = BacktestRun::factory()->for($user)->completedSource()->create([
        'name' => 'Sweep',
        'config_snapshot' => $snapshot,
    ]);
    sourceBotMarket();

    $this->actingAs($user)
        ->post(route('bots.store'), ['source_backtest_run_id' => $run->id])
        ->assertRedirect(route('bots.index'));

    $bot = Bot::where('source_backtest_run_id', $run->id)->firstOrFail();

    expect($bot->strategyConfig->configuration['levels_per_side'])->toBe(3)
        ->and(BacktestComparison::make($bot, $run, '0', 0)->toArray()['modified'])->toBeFalse();
});
