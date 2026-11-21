<?php

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\BacktestMetrics;
use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Backtest\ValueObjects\EquityPoint;
use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

it('exposes timeframe durations, labels, and configured values', function () {
    expect(Timeframe::M1->seconds())->toBe(60)
        ->and(Timeframe::M5->seconds())->toBe(300)
        ->and(Timeframe::M15->seconds())->toBe(900)
        ->and(Timeframe::H1->seconds())->toBe(3600)
        ->and(Timeframe::H4->seconds())->toBe(14400)
        ->and(Timeframe::D1->seconds())->toBe(86400)
        ->and(Timeframe::M15->label())->toBe('15m')
        ->and(Timeframe::values())->toBe(['1m', '5m', '15m', '1h', '4h', '1d'])
        ->and(Timeframe::configured())->toBe(['1m', '5m', '15m', '1h', '4h', '1d'])
        ->and(Timeframe::fromConfig('1h'))->toBe(Timeframe::H1);
});

it('rejects an unsupported timeframe', function () {
    expect(fn () => Timeframe::fromConfig('2h'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported timeframe [2h].');
});

it('normalizes candle times and exchange numbers', function () {
    $candle = Candle::make(1_767_225_600_000, 100.0, 110.5, 90.25, 105.0, 12.0);

    expect($candle->openTime->format(DATE_ATOM))->toBe('2026-01-01T00:00:00+00:00')
        ->and($candle->openTimeMilliseconds())->toBe(1_767_225_600_000)
        ->and($candle->closeTime(Timeframe::M15)->format(DATE_ATOM))->toBe('2026-01-01T00:15:00+00:00')
        ->and($candle->open->toString())->toBe('100')
        ->and($candle->high->toString())->toBe('110.5')
        ->and($candle->low->toString())->toBe('90.25')
        ->and($candle->close->toString())->toBe('105')
        ->and($candle->volume->toString())->toBe('12')
        ->and($candle->toArray())->toMatchArray([
            'open_time' => '2026-01-01T00:00:00+00:00',
            'open' => '100',
            'close' => '105',
        ]);
});

it('serializes fills, equity points, metrics, and configuration without floats', function () {
    $fill = new SimulatedFill(
        sequence: 3,
        side: OrderSide::Buy,
        price: Decimal::of('100'),
        quantity: Decimal::of('1.5'),
        fee: Decimal::of('0.15'),
        feeCurrency: 'USDT',
        filledAt: new DateTimeImmutable('2026-01-01T01:00:00Z'),
        clientOrderId: 'backtest-3',
        gridLevelSequence: 2,
    );
    $point = new EquityPoint(new DateTimeImmutable('2026-01-01T01:00:00Z'), Decimal::of('1010'));
    $metrics = new BacktestMetrics(
        endReason: 'range_end',
        finalEquity: Decimal::of('1010'),
        netPnl: Decimal::of('10'),
        realizedPnl: Decimal::of('5'),
        unrealizedPnl: Decimal::of('5'),
        fees: Decimal::of('1'),
        returnPercent: Decimal::of('1'),
        maxDrawdown: Decimal::of('2'),
        maxDrawdownPercent: Decimal::of('0.2'),
        finalBaseInventory: Decimal::of('0'),
        finalQuoteInventory: Decimal::of('1010'),
        finalPrice: Decimal::of('100'),
        fillCount: 2,
        buyCount: 1,
        sellCount: 1,
        candlesProcessed: 96,
    );

    expect($fill->toArray())->toMatchArray([
        'sequence' => 3,
        'side' => 'buy',
        'price' => '100',
        'quantity' => '1.5',
        'fee' => '0.15',
        'fee_currency' => 'USDT',
        'client_order_id' => 'backtest-3',
        'grid_level_sequence' => 2,
    ])
        ->and($point->toArray())->toBe([
            'at' => '2026-01-01T01:00:00+00:00',
            'equity' => '1010',
        ])
        ->and($metrics->toArray()['final_equity'])->toBe('1010')
        ->and($metrics->toArray()['fill_count'])->toBe(2)
        ->and($metrics->toArray()['duration_ms'])->toBe(0);
});

it('round-trips a configuration through its snapshot', function () {
    $configuration = new BacktestConfiguration(
        botId: 7,
        botName: 'Snapshot bot',
        exchange: 'binance',
        symbol: 'ETH/USDT',
        strategyKey: 'simple-grid',
        timeframe: Timeframe::H1,
        startAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        endAt: new DateTimeImmutable('2026-02-01T00:00:00Z'),
        capitalAllocation: Decimal::of('2500'),
        feeRate: Decimal::of('0.001'),
        strategyConfiguration: ['center_price' => '2000'],
        riskLimits: BacktestRiskLimits::make('5000', 12, '250'),
    );

    $restored = BacktestConfiguration::fromSnapshot($configuration->toSnapshot());

    expect($restored->botId)->toBe(7)
        ->and($restored->botName)->toBe('Snapshot bot')
        ->and($restored->symbol)->toBe('ETH/USDT')
        ->and($restored->timeframe)->toBe(Timeframe::H1)
        ->and($restored->startAt->format(DATE_ATOM))->toBe('2026-01-01T00:00:00+00:00')
        ->and($restored->capitalAllocation->toString())->toBe('2500')
        ->and($restored->riskLimits->maxOpenOrders)->toBe(12)
        ->and($restored->strategyConfiguration)->toBe(['center_price' => '2000']);
});

it('falls back to safe configuration defaults for a partial snapshot', function () {
    $configuration = BacktestConfiguration::fromSnapshot([
        'period' => ['timeframe' => '1d'],
    ]);

    expect($configuration->botId)->toBe(0)
        ->and($configuration->botName)->toBe('Deleted bot')
        ->and($configuration->symbol)->toBe('BTC/USDT')
        ->and($configuration->timeframe)->toBe(Timeframe::D1)
        ->and($configuration->capitalAllocation->toString())->toBe('0')
        ->and($configuration->riskLimits->maxOpenOrders)->toBe(20);
});
