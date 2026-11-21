<?php

use App\Domain\Backtest\Engine\BacktestRiskGuard;
use App\Domain\Backtest\Engine\InMemoryPortfolio;
use App\Domain\Backtest\Engine\MetricsCalculator;
use App\Domain\Backtest\Engine\SimulatedOrderBook;
use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Backtest\ValueObjects\EquityPoint;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

it('implements the close-only fill rule and next-candle eligibility', function () {
    $book = new SimulatedOrderBook('USDT');
    $book->place(OrderSide::Buy, '90', '1', 1, 0);
    $book->place(OrderSide::Sell, '110', '1', 2, 0);

    expect($book->qualifyingOrders(Decimal::of('100'), 0))->toBe([])
        ->and($book->qualifyingOrders(Decimal::of('100'), 1))->toBe([])
        ->and($book->qualifyingOrders(Decimal::of('90'), 1))->toHaveCount(1)
        ->and($book->qualifyingOrders(Decimal::of('110'), 1))->toHaveCount(1);
});

it('orders fills by distance from the close, then price', function () {
    $book = new SimulatedOrderBook('USDT');
    $book->place(OrderSide::Buy, '90', '1', 1, 0);
    $book->place(OrderSide::Buy, '85', '1', 2, 0);

    $qualifying = $book->qualifyingOrders(Decimal::of('80'), 1);

    expect($qualifying[0]->price->toString())->toBe('85')
        ->and($qualifying[1]->price->toString())->toBe('90');
});

it('charges the configured fee on the limit notional', function () {
    $book = new SimulatedOrderBook('USDT');
    $order = $book->place(OrderSide::Buy, '90', '2', 1, 0);
    $fill = $book->fill($order, new DateTimeImmutable('2026-01-01T00:00:00Z'), Decimal::of('0.001'));

    expect($fill->fee->toString())->toBe('0.18')
        ->and($fill->quantity->toString())->toBe('2')
        ->and($fill->price->toString())->toBe('90');
});

it('rejects buys above free capital, position limits, and open-order limits', function () {
    $book = new SimulatedOrderBook('USDT');
    $portfolio = new InMemoryPortfolio('100');
    $guard = new BacktestRiskGuard(BacktestRiskLimits::make('500', 2, '100'), Decimal::of('0.001'));

    $tooLarge = $guard->checkOrder(OrderSide::Buy, '90', '2', $portfolio, $book);
    expect($tooLarge->allowed)->toBeFalse()
        ->and($tooLarge->type)->toBe('risk.insufficient_capital');

    $book = new SimulatedOrderBook('USDT');
    $book->place(OrderSide::Buy, '50', '1', 1, 0);
    $book->place(OrderSide::Buy, '40', '1', 2, 0);
    $openGuard = new BacktestRiskGuard(BacktestRiskLimits::make('5000', 2, '100'), Decimal::of('0.001'));
    $tooMany = $openGuard->checkOrder(OrderSide::Buy, '30', '1', new InMemoryPortfolio('1000'), $book);
    expect($tooMany->allowed)->toBeFalse()
        ->and($tooMany->type)->toBe('risk.open_order_limit');
});

it('rejects an order above the maximum position notional', function () {
    $guard = new BacktestRiskGuard(BacktestRiskLimits::make('50', 20, '100'), Decimal::of('0'));
    $decision = $guard->checkOrder(OrderSide::Buy, '60', '1', new InMemoryPortfolio('1000'), new SimulatedOrderBook);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.position_limit');
});

it('rejects a sell without uncommitted base inventory', function () {
    $guard = new BacktestRiskGuard(BacktestRiskLimits::make('500', 20, '100'), Decimal::of('0.001'));
    $decision = $guard->checkOrder(OrderSide::Sell, '100', '1', new InMemoryPortfolio('100'), new SimulatedOrderBook);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->type)->toBe('risk.insufficient_inventory');
});

it('computes the daily loss from realized and unrealized movement', function () {
    $guard = new BacktestRiskGuard(BacktestRiskLimits::make('500', 20, '10'), Decimal::of('0.001'));

    expect($guard->dailyLoss(Decimal::of('-5'), Decimal::of('-3'), Decimal::of('0'))->toString())->toBe('8')
        ->and($guard->dailyLoss(Decimal::of('5'), Decimal::of('-3'), Decimal::of('0'))->toString())->toBe('0')
        ->and($guard->dailyLoss(Decimal::of('5'), Decimal::of('-10'), Decimal::of('0'))->toString())->toBe('5');
});

it('downsamples equity while preserving first and last samples', function () {
    $points = [];

    for ($i = 0; $i < 2500; $i++) {
        $points[] = new EquityPoint(
            new DateTimeImmutable('@'.(1_700_000_000 + $i * 900)),
            Decimal::of((string) (1000 + $i)),
        );
    }

    $sampled = (new MetricsCalculator)->downsample($points, 1000);

    expect($sampled)->toHaveCount(1000)
        ->and($sampled[0])->toBe($points[0])
        ->and($sampled[999])->toBe($points[2499]);

    expect((new MetricsCalculator)->downsample([], 1000))->toBe([])
        ->and((new MetricsCalculator)->downsample([$points[0]], 1000))->toBe([$points[0]]);
});

it('calculates net return and max drawdown', function () {
    $portfolio = new InMemoryPortfolio('1000');
    $equity = [
        new EquityPoint(new DateTimeImmutable('2026-01-01T00:00:00Z'), Decimal::of('1000')),
        new EquityPoint(new DateTimeImmutable('2026-01-01T00:15:00Z'), Decimal::of('1100')),
        new EquityPoint(new DateTimeImmutable('2026-01-01T00:30:00Z'), Decimal::of('990')),
        new EquityPoint(new DateTimeImmutable('2026-01-01T00:45:00Z'), Decimal::of('1010')),
    ];

    $metrics = (new MetricsCalculator)->calculate(
        $portfolio,
        $equity,
        [],
        Decimal::of('1000'),
        Decimal::of('101'),
        4,
        'range_end',
    );

    expect($metrics->finalEquity->toString())->toBe('1010')
        ->and($metrics->netPnl->toString())->toBe('10')
        ->and($metrics->returnPercent->toString())->toBe('1')
        ->and($metrics->maxDrawdown->toString())->toBe('110')
        ->and($metrics->maxDrawdownPercent->toString())->toBe('10');
});
