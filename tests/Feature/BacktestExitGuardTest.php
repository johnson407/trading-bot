<?php

use App\Domain\Backtest\Engine\BacktestEngine;
use App\Domain\Backtest\Engine\MetricsCalculator;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Exit\ExitRules;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;

function exitGuardCandle(string $openTime, string $close): Candle
{
    return Candle::make($openTime, $close, $close, $close, $close, '1');
}

function exitGuardConfiguration(array $overrides = []): BacktestConfiguration
{
    return new BacktestConfiguration(
        botId: 1,
        botName: 'Exit guard backtest',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'simple-grid',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T02:00:00Z'),
        capitalAllocation: Decimal::of('1000'),
        feeRate: Decimal::of('0.001'),
        strategyConfiguration: [
            'center_price' => '100',
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'levels_per_side' => 2,
        ],
        riskLimits: BacktestRiskLimits::make('1000', 20, $overrides['daily_loss_limit'] ?? '100'),
        exitRules: $overrides['exitRules'] ?? null,
    );
}

function runExitGuardBacktest(array $candles, array $overrides = []): \App\Domain\Backtest\ValueObjects\BacktestResult
{
    return (new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator))
        ->run(exitGuardConfiguration($overrides), $candles);
}

it('ends a replay with stop_loss and leaves the simulated inventory in place', function () {
    $result = runExitGuardBacktest([
        exitGuardCandle('2026-01-01T00:00:00Z', '100'),
        exitGuardCandle('2026-01-01T00:15:00Z', '90'),
        exitGuardCandle('2026-01-01T00:30:00Z', '70'),
    ], ['exitRules' => new ExitRules(stopLossPercent: '10')]);

    expect($result->metrics->endReason)->toBe('stop_loss')
        ->and($result->metrics->candlesProcessed)->toBe(3)
        ->and($result->metrics->finalBaseInventory->toString())->toBe('1')
        ->and($result->equityCurve)->toHaveCount(3)
        ->and($result->fills)->toHaveCount(1);
});

it('ends a replay with take_profit', function () {
    $result = runExitGuardBacktest([
        exitGuardCandle('2026-01-01T00:00:00Z', '100'),
        exitGuardCandle('2026-01-01T00:15:00Z', '90'),
        exitGuardCandle('2026-01-01T00:30:00Z', '100'),
    ], ['exitRules' => new ExitRules(takeProfitPercent: '10')]);

    expect($result->metrics->endReason)->toBe('take_profit')
        ->and($result->metrics->finalBaseInventory->toString())->toBe('1');
});

it('ends a replay with range_exit without requiring inventory', function () {
    $result = runExitGuardBacktest([
        exitGuardCandle('2026-01-01T00:00:00Z', '100'),
        exitGuardCandle('2026-01-01T00:15:00Z', '79'),
    ], ['exitRules' => new ExitRules(rangeLow: '80')]);

    expect($result->metrics->endReason)->toBe('range_exit')
        ->and($result->metrics->finalBaseInventory->toString())->toBe('0');
});

it('gives stop-loss precedence over range and checks exits before the daily loss pause', function () {
    $configuration = exitGuardConfiguration([
        'exitRules' => new ExitRules(stopLossPercent: '10', rangeLow: '85'),
        'daily_loss_limit' => '15',
    ]);

    $result = (new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator))
        ->run($configuration, [
            exitGuardCandle('2026-01-01T00:00:00Z', '100'),
            exitGuardCandle('2026-01-01T00:15:00Z', '90'),
            exitGuardCandle('2026-01-01T00:30:00Z', '80'),
        ]);

    expect($result->metrics->endReason)->toBe('stop_loss');
});

it('keeps range_end and risk_pause when no exit rules are configured', function () {
    $rangeEnd = runExitGuardBacktest([
        exitGuardCandle('2026-01-01T00:00:00Z', '100'),
        exitGuardCandle('2026-01-01T00:15:00Z', '100'),
    ]);

    $riskPause = runExitGuardBacktest([
        exitGuardCandle('2026-01-01T00:00:00Z', '100'),
        exitGuardCandle('2026-01-01T00:15:00Z', '90'),
    ], ['daily_loss_limit' => '0.01']);

    expect($rangeEnd->metrics->endReason)->toBe('range_end')
        ->and($riskPause->metrics->endReason)->toBe('risk_pause');
});
