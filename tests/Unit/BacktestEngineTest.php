<?php

use App\Domain\Backtest\Engine\BacktestEngine;
use App\Domain\Backtest\Engine\MetricsCalculator;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;

function candle(string $openTime, string $close): Candle
{
    return Candle::make($openTime, $close, $close, $close, $close, '1');
}

function backtestConfiguration(array $overrides = []): BacktestConfiguration
{
    return new BacktestConfiguration(
        botId: 1,
        botName: 'Test bot',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'simple-grid',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T02:00:00Z'),
        capitalAllocation: Decimal::of($overrides['capital'] ?? '1000'),
        feeRate: Decimal::of($overrides['fee'] ?? '0.001'),
        strategyConfiguration: [
            'center_price' => '100',
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'levels_per_side' => 2,
        ],
        riskLimits: BacktestRiskLimits::make('1000', 20, '100'),
    );
}

it('initializes and ticks on the first candle without filling same-candle orders', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);

    $result = $engine->run(backtestConfiguration(), [
        candle('2026-01-01T00:00:00Z', '100'),
    ]);

    expect($result->fills)->toBe([])
        ->and($result->metrics->candlesProcessed)->toBe(1)
        ->and($result->metrics->endReason)->toBe('range_end')
        ->and($result->metrics->finalEquity->toString())->toBe('1000');
});

it('fills crossed orders on a later candle and processes fills sequentially', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);

    $result = $engine->run(backtestConfiguration(), [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '90'),
        candle('2026-01-01T00:30:00Z', '110'),
    ]);

    expect($result->fills)->toHaveCount(2)
        ->and($result->fills[0]->side->value)->toBe('buy')
        ->and($result->fills[0]->price->toString())->toBe('90')
        ->and($result->fills[0]->fee->toString())->toBe('0.09')
        ->and($result->fills[1]->side->value)->toBe('sell')
        ->and($result->fills[1]->price->toString())->toBe('110')
        ->and($result->metrics->fillCount)->toBe(2)
        ->and($result->metrics->finalBaseInventory->toString())->toBe('0')
        ->and($result->metrics->finalQuoteInventory->toString())->toBe('1019.8');
});

it('processes multiple fills on the same candle sequentially', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);

    $result = $engine->run(backtestConfiguration(), [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '80'),
    ]);

    expect($result->fills)->toHaveCount(2)
        ->and($result->fills[0]->side->value)->toBe('buy')
        ->and($result->fills[0]->price->toString())->toBe('80')
        ->and($result->fills[1]->side->value)->toBe('buy')
        ->and($result->fills[1]->price->toString())->toBe('90')
        ->and($result->metrics->buyCount)->toBe(2);
});

it('waits for the next candle before filling an order placed by onFill', function () {
    $registry = new StrategyRegistry;
    $registry->register(new class implements StrategyInterface
    {
        public function key(): string
        {
            return 'same-candle';
        }

        public function initialize(StrategyInput $input): StrategyDecision
        {
            return new StrategyDecision(
                state: $input->state->merge(['initialized' => true]),
                levels: [
                    GridLevelPlan::make(1, '90', 'buy'),
                    GridLevelPlan::make(2, '80', 'buy'),
                ],
            );
        }

        public function onTick(StrategyInput $input): StrategyDecision
        {
            $levels = [
                GridLevelPlan::make(1, '90', 'buy'),
                GridLevelPlan::make(2, '80', 'buy'),
            ];

            if ($input->state->get('orders_placed') === true) {
                return new StrategyDecision(state: $input->state, levels: $levels);
            }

            return new StrategyDecision(
                state: $input->state->merge(['orders_placed' => true]),
                levels: $levels,
                orders: [
                    OrderIntent::limit('buy', '90', '1', 1),
                    OrderIntent::limit('buy', '80', '1', 2),
                ],
            );
        }

        public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
        {
            return new StrategyDecision(
                state: $input->state,
                orders: [OrderIntent::limit('sell', '80', $fill->quantity)],
            );
        }

        public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function shouldStop(StrategyInput $input): bool
        {
            return false;
        }

        public function getGridLevels(StrategyInput $input): array
        {
            return [
                GridLevelPlan::make(1, '90', 'buy'),
                GridLevelPlan::make(2, '80', 'buy'),
            ];
        }
    });

    $configuration = new BacktestConfiguration(
        botId: 1,
        botName: 'Same candle',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'same-candle',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T01:00:00Z'),
        capitalAllocation: Decimal::of('1000'),
        feeRate: Decimal::of('0'),
        strategyConfiguration: [],
        riskLimits: BacktestRiskLimits::make('1000', 20, '100'),
    );
    $engine = new BacktestEngine($registry, new MetricsCalculator);

    $twoCandles = $engine->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '80'),
    ]);
    $threeCandles = $engine->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '80'),
        candle('2026-01-01T00:30:00Z', '80'),
    ]);

    expect($twoCandles->fills)->toHaveCount(2)
        ->and(array_map(fn ($fill) => $fill->side->value, $twoCandles->fills))->toBe(['buy', 'buy'])
        ->and($threeCandles->fills)->toHaveCount(3)
        ->and($threeCandles->fills[2]->side->value)->toBe('sell')
        ->and($threeCandles->fills[2]->price->toString())->toBe('80');
});

it('uses the opposite side for the live-style adjacent order at an outer level', function () {
    $registry = new StrategyRegistry;
    $registry->register(new class implements StrategyInterface
    {
        public function key(): string
        {
            return 'outer-opposite';
        }

        public function initialize(StrategyInput $input): StrategyDecision
        {
            return new StrategyDecision(
                state: $input->state->merge(['initialized' => true]),
                levels: [
                    GridLevelPlan::make(1, '80', 'buy'),
                    GridLevelPlan::make(2, '90', 'buy'),
                ],
            );
        }

        public function onTick(StrategyInput $input): StrategyDecision
        {
            $levels = [
                GridLevelPlan::make(1, '80', 'buy'),
                GridLevelPlan::make(2, '90', 'buy'),
            ];

            if ($input->state->get('orders_placed') === true) {
                return new StrategyDecision(state: $input->state, levels: $levels);
            }

            return new StrategyDecision(
                state: $input->state->merge(['orders_placed' => true]),
                levels: $levels,
                orders: [OrderIntent::limit('buy', '80', '1', 1)],
            );
        }

        public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function shouldStop(StrategyInput $input): bool
        {
            return false;
        }

        public function getGridLevels(StrategyInput $input): array
        {
            return [];
        }
    });

    $configuration = new BacktestConfiguration(
        botId: 1,
        botName: 'Outer opposite',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'outer-opposite',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T01:00:00Z'),
        capitalAllocation: Decimal::of('1000'),
        feeRate: Decimal::of('0'),
        strategyConfiguration: [],
        riskLimits: BacktestRiskLimits::make('1000', 20, '100'),
    );

    $result = (new BacktestEngine($registry, new MetricsCalculator))->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '80'),
        candle('2026-01-01T00:30:00Z', '100'),
    ]);

    expect($result->fills)->toHaveCount(2)
        ->and($result->fills[1]->side->value)->toBe('sell')
        ->and($result->fills[1]->price->toString())->toBe('90');
});

it('stops a pathological fill-heavy run at the configured fill budget', function () {
    config(['backtest.max_fills' => 1]);

    try {
        $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);

        expect(fn () => $engine->run(backtestConfiguration(), [
            candle('2026-01-01T00:00:00Z', '100'),
            candle('2026-01-01T00:15:00Z', '80'),
        ]))->toThrow(RuntimeException::class, 'more than 1 fills');
    } finally {
        config(['backtest.max_fills' => 25_000]);
    }
});

it('rejects an unaffordable buy and keeps the run going', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);
    $configuration = backtestConfiguration(['capital' => '50']);

    $result = $engine->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '90'),
    ]);

    expect($result->fills)->toBe([])
        ->and($result->metrics->endReason)->toBe('range_end')
        ->and($result->metrics->candlesProcessed)->toBe(2);
});

it('uses the candle close time as the replay clock', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);
    $result = $engine->run(backtestConfiguration(), [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '100'),
    ]);

    expect($result->equityCurve[0]->at->format(DATE_ATOM))->toBe('2026-01-01T00:15:00+00:00')
        ->and($result->equityCurve[1]->at->format(DATE_ATOM))->toBe('2026-01-01T00:30:00+00:00');
});

it('ends with a risk pause when the daily loss limit is breached', function () {
    $engine = new BacktestEngine(app(StrategyRegistry::class), new MetricsCalculator);
    $configuration = backtestConfiguration();
    $configuration = new BacktestConfiguration(
        botId: $configuration->botId,
        botName: $configuration->botName,
        exchange: $configuration->exchange,
        symbol: $configuration->symbol,
        strategyKey: $configuration->strategyKey,
        timeframe: $configuration->timeframe,
        startAt: $configuration->startAt,
        endAt: $configuration->endAt,
        capitalAllocation: $configuration->capitalAllocation,
        feeRate: $configuration->feeRate,
        strategyConfiguration: $configuration->strategyConfiguration,
        riskLimits: BacktestRiskLimits::make('1000', 20, '0.01'),
    );

    $result = $engine->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
        candle('2026-01-01T00:15:00Z', '90'),
    ]);

    expect($result->metrics->endReason)->toBe('risk_pause')
        ->and($result->metrics->candlesProcessed)->toBe(2)
        ->and($result->fills)->toHaveCount(1);
});

it('ends with a strategy stop when the strategy asks to stop', function () {
    $registry = new StrategyRegistry;
    $registry->register(new class implements StrategyInterface
    {
        public function key(): string
        {
            return 'stopper';
        }

        public function initialize(StrategyInput $input): StrategyDecision
        {
            return StrategyDecision::idle($input->state->merge(['initialized' => true]));
        }

        public function onTick(StrategyInput $input): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
        {
            return StrategyDecision::idle($input->state);
        }

        public function shouldStop(StrategyInput $input): bool
        {
            return true;
        }

        public function getGridLevels(StrategyInput $input): array
        {
            return [];
        }
    });

    $engine = new BacktestEngine($registry, new MetricsCalculator);
    $configuration = new BacktestConfiguration(
        botId: 1,
        botName: 'Stopper',
        exchange: 'binance',
        symbol: 'BTC/USDT',
        strategyKey: 'stopper',
        timeframe: Timeframe::M15,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-01-01T00:15:00Z'),
        capitalAllocation: Decimal::of('1000'),
        feeRate: Decimal::of('0'),
        strategyConfiguration: [],
        riskLimits: BacktestRiskLimits::make('1000', 20, '100'),
    );

    $result = $engine->run($configuration, [
        candle('2026-01-01T00:00:00Z', '100'),
    ]);

    expect($result->metrics->endReason)->toBe('strategy_stop')
        ->and($result->metrics->candlesProcessed)->toBe(1);
});
