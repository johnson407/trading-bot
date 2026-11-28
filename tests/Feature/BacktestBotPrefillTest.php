<?php

use App\Models\BacktestRun;
use App\Services\Backtest\BacktestBotPrefill;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backtestToBotSnapshot(string $strategy, array $configuration, array $exits = []): array
{
    return [
        'bot' => [
            'id' => null,
            'name' => 'Source bot',
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'strategy_key' => $strategy,
            'capital_allocation' => '1000',
            'fee_rate' => '0.001',
        ],
        'strategy' => [
            'key' => $strategy,
            'configuration' => $configuration,
        ],
        'risk' => [
            'max_position_notional' => '5000',
            'max_open_orders' => 10,
            'daily_loss_limit' => '100',
        ],
        'exit' => array_merge([
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ], $exits),
        'period' => [
            'timeframe' => '15m',
            'start_at' => '2026-01-01T00:00:00+00:00',
            'end_at' => '2026-01-31T00:00:00+00:00',
        ],
    ];
}

function backtestToBotRun(array $snapshot, string $name = 'Source backtest'): BacktestRun
{
    return BacktestRun::factory()->completedSource()->create([
        'name' => $name,
        'strategy_key' => $snapshot['strategy']['key'],
        'exchange' => $snapshot['bot']['exchange'],
        'symbol' => $snapshot['bot']['symbol'],
        'config_snapshot' => $snapshot,
    ]);
}

function backtestToBotSimpleConfiguration(array $overrides = []): array
{
    return array_merge([
        'center_price' => '100',
        'levels_per_side' => 4,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '1',
        'order_quantity' => '1',
    ], $overrides);
}

it('supports every built-in grid strategy', function () {
    $prefill = app(BacktestBotPrefill::class);

    $configurations = [
        'simple-grid' => backtestToBotSimpleConfiguration(),
        'trailing-grid' => backtestToBotSimpleConfiguration(['trailing_distance' => '2']),
        'martingale-grid' => backtestToBotSimpleConfiguration([
            'base_order_quantity' => '1',
            'size_multiplier' => '2',
            'max_order_quantity' => '4',
            'max_increases' => 3,
        ]),
        'dynamic-grid' => backtestToBotSimpleConfiguration([
            'spacing_value' => '0',
            'min_spacing' => '1',
            'max_spacing' => '5',
            'volatility_multiplier' => '1.5',
            'recalculation_interval_seconds' => 60,
        ]),
    ];

    foreach ($configurations as $strategy => $configuration) {
        expect($prefill->supports(backtestToBotSnapshot($strategy, $configuration)))->toBeTrue();
    }
});

it('maps common settings and sweep level overrides', function () {
    $snapshot = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration([
        'level_count' => 9,
        'levels_per_side' => 4,
    ]));

    $run = backtestToBotRun($snapshot, 'Momentum');
    $values = app(BacktestBotPrefill::class)->fromRun($run);

    expect($values['source_backtest_run_id'])->toBe($run->id)
        ->and($values['name'])->toBe('Momentum paper')
        ->and($values['exchange'])->toBe('binance')
        ->and($values['symbol'])->toBe('BTC/USDT')
        ->and($values['strategy_key'])->toBe('simple-grid')
        ->and($values['mode'])->toBe('paper')
        ->and($values['capital_allocation'])->toBe('1000')
        ->and($values['fee_rate'])->toBe('0.001')
        ->and($values['center_price'])->toBe('100')
        ->and($values['level_count'])->toBe(9)
        ->and($values['spacing_type'])->toBe('arithmetic')
        ->and($values['spacing_value'])->toBe('1')
        ->and($values['order_quantity'])->toBe('1');
});

it('falls back to levels_per_side when level_count is absent', function () {
    $snapshot = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration([
        'levels_per_side' => 4,
    ]));

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['level_count'])->toBe(4);
});

it('maps trailing-grid settings', function () {
    $snapshot = backtestToBotSnapshot('trailing-grid', backtestToBotSimpleConfiguration([
        'trailing_distance' => '2',
    ]));

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['trailing_distance'])->toBe('2');
});

it('maps martingale-grid settings', function () {
    $snapshot = backtestToBotSnapshot('martingale-grid', backtestToBotSimpleConfiguration([
        'base_order_quantity' => '1',
        'size_multiplier' => '2',
        'max_order_quantity' => '4',
        'max_increases' => 3,
    ]));

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['size_multiplier'])->toBe('2')
        ->and($values['max_order_quantity'])->toBe('4')
        ->and($values['max_increases'])->toBe(3);
});

it('maps dynamic-grid settings and omits zero spacing', function () {
    $snapshot = backtestToBotSnapshot('dynamic-grid', backtestToBotSimpleConfiguration([
        'spacing_value' => '0',
        'min_spacing' => '1',
        'max_spacing' => '5',
        'initial_spacing' => '2',
        'volatility_multiplier' => '1.5',
        'recalculation_interval_seconds' => 60,
    ]));

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['min_spacing'])->toBe('1')
        ->and($values['max_spacing'])->toBe('5')
        ->and($values['initial_spacing'])->toBe('2')
        ->and($values['volatility_multiplier'])->toBe('1.5')
        ->and($values['recalculation_interval_seconds'])->toBe(60)
        ->and($values['spacing_value'])->toBeNull();
});

it('copies protective exits when present', function () {
    $snapshot = backtestToBotSnapshot(
        'simple-grid',
        backtestToBotSimpleConfiguration(),
        [
            'stop_loss_percent' => '4',
            'take_profit_percent' => '9',
            'range_low' => '80000',
            'range_high' => '120000',
        ],
    );

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['stop_loss_percent'])->toBe('4')
        ->and($values['take_profit_percent'])->toBe('9')
        ->and($values['range_low'])->toBe('80000')
        ->and($values['range_high'])->toBe('120000');
});

it('returns null exits so creation defaults apply', function () {
    $snapshot = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration());

    $values = app(BacktestBotPrefill::class)->fromRun(backtestToBotRun($snapshot));

    expect($values['stop_loss_percent'])->toBeNull()
        ->and($values['take_profit_percent'])->toBeNull()
        ->and($values['range_low'])->toBeNull()
        ->and($values['range_high'])->toBeNull();
});

it('rejects snapshots with an inverted protective range', function () {
    $snapshot = backtestToBotSnapshot(
        'simple-grid',
        backtestToBotSimpleConfiguration(),
        ['range_low' => '120000', 'range_high' => '80000'],
    );

    expect(app(BacktestBotPrefill::class)->supports($snapshot))->toBeFalse();
});

it('truncates the default name to 120 characters', function () {
    $snapshot = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration());

    $values = app(BacktestBotPrefill::class)->fromRun(
        backtestToBotRun($snapshot, str_repeat('a', 130)),
    );

    expect(mb_strlen($values['name']))->toBe(120);
});

it('rejects incomplete or unknown strategy snapshots', function () {
    $prefill = app(BacktestBotPrefill::class);

    $missingConfiguration = backtestToBotSnapshot('simple-grid', []);
    expect($prefill->supports($missingConfiguration))->toBeFalse();

    $unknown = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration());
    $unknown['bot']['strategy_key'] = 'unknown';
    $unknown['strategy']['key'] = 'unknown';
    expect($prefill->supports($unknown))->toBeFalse();

    expect($prefill->supports(null))->toBeFalse();
});

it('rejects runs that are not completed', function () {
    $snapshot = backtestToBotSnapshot('simple-grid', backtestToBotSimpleConfiguration());
    $run = BacktestRun::factory()->create([
        'strategy_key' => 'simple-grid',
        'config_snapshot' => $snapshot,
    ]);

    app(BacktestBotPrefill::class)->fromRun($run);
})->throws(InvalidArgumentException::class);
