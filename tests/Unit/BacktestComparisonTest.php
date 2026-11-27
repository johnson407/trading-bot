<?php

use App\Domain\Backtest\BacktestComparison;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\StrategyConfig;
use Carbon\CarbonImmutable;

function comparisonSnapshot(): array
{
    return [
        'bot' => [
            'capital_allocation' => '1000',
            'fee_rate' => '0.001',
        ],
        'strategy' => [
            'key' => 'simple-grid',
            'configuration' => [
                'center_price' => '100',
                'levels_per_side' => 2,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '1',
                'order_quantity' => '1',
            ],
        ],
        'exit' => [
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ],
    ];
}

function comparisonBot(array $attributes = []): Bot
{
    $bot = new Bot;
    $bot->forceFill(array_merge([
        'name' => 'Paper bot',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'realized_pnl' => '30',
        'fees_paid' => '1',
        'stop_loss_percent' => null,
        'take_profit_percent' => null,
        'range_low' => null,
        'range_high' => null,
    ], $attributes));

    $config = new StrategyConfig([
        'strategy_key' => 'simple-grid',
        'configuration' => comparisonSnapshot()['strategy']['configuration'],
    ]);
    $bot->setRelation('strategyConfig', $config);

    return $bot;
}

function comparisonSource(?array $metrics = null, array $overrides = []): BacktestRun
{
    $source = new BacktestRun;
    $source->forceFill(array_merge([
        'id' => 99,
        'name' => 'Momentum backtest',
        'symbol' => 'BTC/USDT',
        'timeframe' => '15m',
        'capital_allocation' => '1000',
        'fee_rate' => '0.001',
        'status' => BacktestStatus::Completed,
        'start_at' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        'end_at' => CarbonImmutable::parse('2026-02-01T00:00:00Z'),
        'config_snapshot' => comparisonSnapshot(),
        'metrics' => $metrics ?? [
            'net_pnl' => '50',
            'return_percent' => '5',
            'fees' => '2.5',
            'fill_count' => 12,
            'max_drawdown' => '15',
            'max_drawdown_percent' => '1.5',
        ],
    ], $overrides));

    return $source;
}

it('computes both result sets and a zero delta for equal five percent returns', function () {
    $comparison = BacktestComparison::make(comparisonBot(), comparisonSource(), '20', 4)->toArray();

    expect($comparison['source']['id'])->toBe(99)
        ->and($comparison['source']['name'])->toBe('Momentum backtest')
        ->and($comparison['source']['status'])->toBe('completed')
        ->and($comparison['source']['net_pnl'])->toBe('50')
        ->and($comparison['source']['return_percent'])->toBe('5')
        ->and($comparison['source']['fees'])->toBe('2.5')
        ->and($comparison['source']['fill_count'])->toBe(12)
        ->and($comparison['source']['max_drawdown'])->toBe('15')
        ->and($comparison['source']['metrics_available'])->toBeTrue()
        ->and($comparison['paper']['net_pnl'])->toBe('50')
        ->and($comparison['paper']['return_percent'])->toBe('5')
        ->and($comparison['paper']['fill_count'])->toBe(4)
        ->and($comparison['deltas']['net_pnl'])->toBe('0')
        ->and($comparison['deltas']['return_percent_points'])->toBe('0')
        ->and($comparison['deltas']['fees'])->toBe('-1.5')
        ->and($comparison['deltas']['fill_count'])->toBe(-8)
        ->and($comparison['modified'])->toBeFalse();
});

it('computes paper minus backtest deltas in percentage points', function () {
    $bot = comparisonBot([
        'realized_pnl' => '60',
        'fees_paid' => '3.5',
    ]);

    $comparison = BacktestComparison::make($bot, comparisonSource(), '15', 4)->toArray();

    expect($comparison['paper']['net_pnl'])->toBe('75')
        ->and($comparison['paper']['return_percent'])->toBe('7.5')
        ->and($comparison['deltas']['net_pnl'])->toBe('25')
        ->and($comparison['deltas']['return_percent_points'])->toBe('2.5')
        ->and($comparison['deltas']['fees'])->toBe('1')
        ->and($comparison['deltas']['fill_count'])->toBe(-8);
});

it('returns null returns when either capital is zero', function () {
    $bot = comparisonBot(['capital_allocation' => '0']);
    $snapshot = comparisonSnapshot();
    $snapshot['bot']['capital_allocation'] = '0';
    $source = comparisonSource(overrides: [
        'capital_allocation' => '0',
        'config_snapshot' => $snapshot,
    ]);

    $comparison = BacktestComparison::make($bot, $source, '20', 0)->toArray();

    expect($comparison['paper']['return_percent'])->toBeNull()
        ->and($comparison['source']['return_percent'])->toBeNull()
        ->and($comparison['deltas']['return_percent_points'])->toBeNull()
        ->and($comparison['deltas']['net_pnl'])->toBe('0');
});

it('marks missing source metrics as unavailable without fabricating values', function () {
    $comparison = BacktestComparison::make(comparisonBot(), comparisonSource([]), '20', 0)->toArray();

    expect($comparison['source']['metrics_available'])->toBeFalse()
        ->and($comparison['source']['net_pnl'])->toBeNull()
        ->and($comparison['source']['return_percent'])->toBeNull()
        ->and($comparison['source']['fees'])->toBeNull()
        ->and($comparison['source']['fill_count'])->toBeNull()
        ->and($comparison['deltas']['net_pnl'])->toBeNull()
        ->and($comparison['deltas']['return_percent_points'])->toBeNull()
        ->and($comparison['deltas']['fees'])->toBeNull()
        ->and($comparison['deltas']['fill_count'])->toBeNull()
        ->and($comparison['paper']['net_pnl'])->toBe('50')
        ->and($comparison['paper']['fill_count'])->toBe(0);
});

it('flags capital, fee, strategy, and protective exit changes as modified', function () {
    expect(BacktestComparison::make(comparisonBot(), comparisonSource(), '20', 0)->toArray()['modified'])
        ->toBeFalse();

    expect(BacktestComparison::make(comparisonBot(['capital_allocation' => '1200']), comparisonSource(), '20', 0)->toArray()['modified'])
        ->toBeTrue();

    expect(BacktestComparison::make(comparisonBot(['fee_rate' => '0.002']), comparisonSource(), '20', 0)->toArray()['modified'])
        ->toBeTrue();

    $changedStrategy = comparisonBot();
    $changedStrategy->strategyConfig->configuration = [
        'center_price' => '101',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '1',
        'order_quantity' => '1',
    ];

    expect(BacktestComparison::make($changedStrategy, comparisonSource(), '20', 0)->toArray()['modified'])
        ->toBeTrue();

    $source = comparisonSource(overrides: [
        'config_snapshot' => array_replace_recursive(comparisonSnapshot(), [
            'exit' => ['stop_loss_percent' => '4'],
        ]),
    ]);

    expect(BacktestComparison::make(comparisonBot(['stop_loss_percent' => '4']), $source, '20', 0)->toArray()['modified'])
        ->toBeFalse();

    expect(BacktestComparison::make(comparisonBot(['stop_loss_percent' => '5']), $source, '20', 0)->toArray()['modified'])
        ->toBeTrue();
});

it('does not treat sweep level_count aliases as modifications', function () {
    $snapshot = comparisonSnapshot();
    $snapshot['strategy']['configuration']['level_count'] = 2;

    $source = comparisonSource(overrides: ['config_snapshot' => $snapshot]);

    expect(BacktestComparison::make(comparisonBot(), $source, '20', 0)->toArray()['modified'])
        ->toBeFalse();
});

it('does not treat dynamic-grid spacing as a modification', function () {
    $bot = comparisonBot(['strategy_key' => 'dynamic-grid']);
    $bot->setRelation('strategyConfig', new StrategyConfig([
        'strategy_key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '0',
            'order_quantity' => '1',
            'min_spacing' => '1',
            'max_spacing' => '5',
            'volatility_multiplier' => '1',
            'recalculation_interval_seconds' => 60,
        ],
    ]));

    $snapshot = comparisonSnapshot();
    $snapshot['strategy'] = [
        'key' => 'dynamic-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '0',
            'order_quantity' => '1',
            'min_spacing' => '1',
            'max_spacing' => '5',
            'volatility_multiplier' => '1',
            'recalculation_interval_seconds' => 60,
        ],
    ];

    $source = comparisonSource(overrides: ['config_snapshot' => $snapshot]);

    expect(BacktestComparison::make($bot, $source, '20', 0)->toArray()['modified'])
        ->toBeFalse();
});

it('includes the comparison caveat', function () {
    $caveat = BacktestComparison::make(comparisonBot(), comparisonSource(), '20', 0)->toArray()['caveat'];

    expect($caveat)->toContain('periods')
        ->toContain('close-only')
        ->toContain('live market data')
        ->toContain('Risk limits')
        ->toContain('edited');
});
