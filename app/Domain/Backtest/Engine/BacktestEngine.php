<?php

namespace App\Domain\Backtest\Engine;

use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\BacktestResult;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Backtest\ValueObjects\EquityPoint;
use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Backtest\ValueObjects\SimulatedOrder;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Domain\Support\Decimal;
use RuntimeException;

final class BacktestEngine
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly MetricsCalculator $metrics,
    ) {}

    /**
     * Replay a snapshot against a contiguous candle sequence.
     *
     * @param  iterable<Candle>  $candles
     * @param  (callable(int): void)|null  $onProgress
     */
    public function run(
        BacktestConfiguration $configuration,
        iterable $candles,
        ?callable $onProgress = null,
    ): BacktestResult {
        $started = hrtime(true);
        $strategy = $this->strategies->get($configuration->strategyKey);
        $strategyConfiguration = new StrategyConfiguration(
            $configuration->strategyKey,
            $configuration->strategyConfiguration,
        );

        [, $quoteAsset] = array_pad(explode('/', $configuration->symbol), 2, 'USDT');
        $portfolio = new InMemoryPortfolio($configuration->capitalAllocation);
        $book = new SimulatedOrderBook($quoteAsset);
        $risk = new BacktestRiskGuard($configuration->riskLimits, $configuration->feeRate);

        $state = StrategyState::empty();
        $recentPrices = [];
        /** @var list<GridLevelPlan>|null $activeLevels */
        $activeLevels = null;
        /** @var list<EquityPoint> $equity */
        $equity = [];
        /** @var list<SimulatedFill> $fills */
        $fills = [];
        $processed = 0;
        $lastPrice = Decimal::zero();
        $endReason = 'range_end';
        $dayKey = null;
        $dayStartRealized = Decimal::zero();
        $progressInterval = max(1, (int) config('backtest.progress_interval', 500));
        $maxFills = max(1, (int) config('backtest.max_fills', 25_000));

        foreach ($candles as $candle) {
            if (! $candle instanceof Candle) {
                throw new RuntimeException('Backtest candles must be Candle value objects.');
            }

            $index = $processed;
            $close = $candle->close;
            $lastPrice = $close;
            $at = $candle->closeTime($configuration->timeframe);
            $recentPrices[] = $close->toString();
            $recentPrices = array_slice($recentPrices, -30);

            $day = $at->format('Y-m-d');

            if ($dayKey === null || $day !== $dayKey) {
                $dayKey = $day;
                $dayStartRealized = $portfolio->realized;
            }

            $exitSignal = $configuration->exitRules->evaluate(
                $close->toString(),
                $portfolio->averageEntry->toString(),
                $portfolio->base->toString(),
            );

            if ($exitSignal !== null) {
                $state = $this->cancelAll($book, $strategy, $configuration, $strategyConfiguration, $state, $portfolio, $close, $recentPrices, $at);
                $equity[] = new EquityPoint($at, $portfolio->equity($close));
                $processed++;
                $endReason = $exitSignal->rule->value;
                break;
            }

            if ($this->dailyLossBreached($risk, $portfolio, $close, $dayStartRealized)) {
                $state = $this->cancelAll($book, $strategy, $configuration, $strategyConfiguration, $state, $portfolio, $close, $recentPrices, $at);
                $equity[] = new EquityPoint($at, $portfolio->equity($close));
                $processed++;
                $endReason = 'risk_pause';
                break;
            }

            $market = new MarketSnapshot(
                symbol: $configuration->symbol,
                price: $close,
                bid: $close,
                ask: $close,
                recentPrices: $recentPrices,
                timestamp: $at,
            );

            $input = $this->inputFor(
                $configuration,
                $strategyConfiguration,
                $portfolio,
                $book,
                $state,
                $market,
            );

            if ($index === 0) {
                $initialized = $strategy->initialize($input);
                $decision = $strategy->onTick($input->withState($initialized->state));
            } else {
                $decision = $strategy->onTick($input);
            }

            $state = $decision->state;
            $previousLevels = $activeLevels;
            [$activeLevels, $canceled] = $this->syncGrid($activeLevels, $decision->levels, $book);
            $cancelEvents = $this->cancelEvents($canceled);

            if ($previousLevels !== null && $this->gridReplaced($previousLevels, $activeLevels)) {
                // A replacement cancels every previous order. Re-evaluate once
                // with the new order book so retained levels get orders again.
                $decision = $strategy->onTick($this->inputFor(
                    $configuration,
                    $strategyConfiguration,
                    $portfolio,
                    $book,
                    $state,
                    $market,
                ));
                $state = $decision->state;
                [$activeLevels, $canceledAgain] = $this->syncGrid($activeLevels, $decision->levels, $book);
                $cancelEvents = [...$cancelEvents, ...$this->cancelEvents($canceledAgain)];
            }

            foreach ($decision->cancelClientOrderIds as $clientOrderId) {
                $canceledOrder = $book->cancel($clientOrderId);

                if ($canceledOrder instanceof SimulatedOrder) {
                    $cancelEvents[] = new CancelEvent(
                        $canceledOrder->clientOrderId,
                        $canceledOrder->gridLevelSequence,
                        'strategy',
                    );
                }
            }

            foreach ($cancelEvents as $event) {
                $state = $strategy->onCancel($this->inputFor(
                    $configuration,
                    $strategyConfiguration,
                    $portfolio,
                    $book,
                    $state,
                    $market,
                ), $event)->state;
            }

            if ($activeLevels !== null) {
                $this->placeIntents(
                    $decision->orders,
                    $book,
                    $risk,
                    $portfolio,
                    $index,
                );
            }

            foreach ($book->qualifyingOrders($close, $index) as $order) {
                if (! $order->isOpen()) {
                    continue;
                }

                if (count($fills) >= $maxFills) {
                    throw new RuntimeException(
                        "Backtest produced more than {$maxFills} fills; choose a shorter range or a larger timeframe.",
                    );
                }

                $fill = $book->fill($order, $at, $configuration->feeRate);
                $portfolio->apply($fill);
                $fills[] = $fill;

                $fillDecision = $strategy->onFill(
                    $this->inputFor(
                        $configuration,
                        $strategyConfiguration,
                        $portfolio,
                        $book,
                        $state,
                        $market,
                    ),
                    FillEvent::make(
                        exchangeFillId: 'backtest-'.$fill->sequence,
                        side: $fill->side,
                        price: $fill->price,
                        quantity: $fill->quantity,
                        fee: $fill->fee,
                        clientOrderId: $fill->clientOrderId,
                        gridLevelSequence: $fill->gridLevelSequence,
                    ),
                );

                $state = $fillDecision->state;
                // Live computes the opposite intent from the grid that was
                // active when the order filled, before any grid replacement.
                $opposite = $this->oppositeIntent($activeLevels, $fill);
                [$activeLevels, $canceledByFill] = $this->syncGrid($activeLevels, $fillDecision->levels, $book);

                foreach ($this->cancelEvents($canceledByFill) as $event) {
                    $state = $strategy->onCancel($this->inputFor(
                        $configuration,
                        $strategyConfiguration,
                        $portfolio,
                        $book,
                        $state,
                        $market,
                    ), $event)->state;
                }

                if ($activeLevels !== null) {
                    $intents = $fillDecision->orders;

                    if ($opposite instanceof OrderIntent) {
                        $intents[] = $opposite;
                    }

                    $this->placeIntents(
                        $intents,
                        $book,
                        $risk,
                        $portfolio,
                        $index,
                    );
                }
            }

            if ($this->dailyLossBreached($risk, $portfolio, $close, $dayStartRealized)) {
                $state = $this->cancelAll($book, $strategy, $configuration, $strategyConfiguration, $state, $portfolio, $close, $recentPrices, $at);
                $equity[] = new EquityPoint($at, $portfolio->equity($close));
                $processed++;
                $endReason = 'risk_pause';
                break;
            }

            $shouldStopInput = $this->inputFor(
                $configuration,
                $strategyConfiguration,
                $portfolio,
                $book,
                $state,
                $market,
            );

            if ($decision->stop || $strategy->shouldStop($shouldStopInput)) {
                $state = $this->cancelAll($book, $strategy, $configuration, $strategyConfiguration, $state, $portfolio, $close, $recentPrices, $at);
                $equity[] = new EquityPoint($at, $portfolio->equity($close));
                $processed++;
                $endReason = 'strategy_stop';
                break;
            }

            $equity[] = new EquityPoint($at, $portfolio->equity($close));
            $processed++;

            if ($onProgress !== null && $processed % $progressInterval === 0) {
                $onProgress($processed);
            }
        }

        if ($processed === 0) {
            throw new RuntimeException('No historical data available.');
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $metrics = $this->metrics->calculate(
            portfolio: $portfolio,
            equity: $equity,
            fills: $fills,
            capital: $configuration->capitalAllocation,
            lastPrice: $lastPrice,
            candlesProcessed: $processed,
            endReason: $endReason,
            durationMs: $durationMs,
        );

        return new BacktestResult(
            metrics: $metrics,
            fills: $fills,
            equityCurve: $this->metrics->downsample($equity),
            finalState: $state,
        );
    }

    /**
     * Mirror the live FillProcessor's adjacent opposite order. The built-in
     * strategies already return this order; placeIntents() de-duplicates it.
     *
     * @param  list<GridLevelPlan>  $levels
     */
    private function oppositeIntent(array $levels, SimulatedFill $fill): ?OrderIntent
    {
        if ($fill->gridLevelSequence === null) {
            return null;
        }

        $target = $fill->side === OrderSide::Buy
            ? $fill->gridLevelSequence + 1
            : $fill->gridLevelSequence - 1;

        foreach ($levels as $level) {
            if ($level->sequence === $target) {
                return OrderIntent::limit(
                    side: $fill->side->opposite(),
                    price: $level->price,
                    quantity: $fill->quantity,
                    gridLevelSequence: $level->sequence,
                    reason: 'fill.opposite',
                );
            }
        }

        return null;
    }

    /**
     * @param  list<OrderIntent>  $intents
     */
    private function placeIntents(
        array $intents,
        SimulatedOrderBook $book,
        BacktestRiskGuard $risk,
        InMemoryPortfolio $portfolio,
        int $candleIndex,
    ): void {
        foreach ($intents as $intent) {
            if (! $intent instanceof OrderIntent) {
                continue;
            }

            if ($book->hasOpenAtPrice($intent->price)) {
                continue;
            }

            if ($intent->gridLevelSequence !== null && $book->openForSequence($intent->gridLevelSequence) !== null) {
                continue;
            }

            $decision = $risk->checkOrder(
                $intent->side,
                $intent->price,
                $intent->quantity,
                $portfolio,
                $book,
            );

            if (! $decision->allowed) {
                continue;
            }

            $book->place(
                $intent->side,
                $intent->price,
                $intent->quantity,
                $intent->gridLevelSequence,
                $candleIndex,
                $intent->reason,
            );
        }
    }

    /**
     * @param  list<GridLevelPlan>  $levels
     * @return array{0: list<GridLevelPlan>|null, 1: list<SimulatedOrder>}
     */
    private function syncGrid(?array $active, array $levels, SimulatedOrderBook $book): array
    {
        if ($levels === []) {
            return [$active, []];
        }

        if ($active !== null && $this->levelsMatch($active, $levels)) {
            return [$active, []];
        }

        $canceled = $active === null ? [] : $book->cancelAll();

        return [array_values($levels), $canceled];
    }

    /** @param list<GridLevelPlan> $active @param list<GridLevelPlan> $levels */
    private function levelsMatch(array $active, array $levels): bool
    {
        if (count($active) !== count($levels)) {
            return false;
        }

        foreach ($levels as $index => $level) {
            $current = $active[$index];

            if ($current->sequence !== $level->sequence
                || ! $current->price->equals($level->price)
                || $current->side !== $level->side) {
                return false;
            }
        }

        return true;
    }

    /** @param list<GridLevelPlan>|null $before @param list<GridLevelPlan>|null $after */
    private function gridReplaced(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null || $before === $after) {
            return false;
        }

        return ! $this->levelsMatch($before, $after);
    }

    /** @param list<SimulatedOrder> $orders @return list<CancelEvent> */
    private function cancelEvents(array $orders): array
    {
        return array_map(
            fn (SimulatedOrder $order) => new CancelEvent(
                $order->clientOrderId,
                $order->gridLevelSequence,
                'grid_replaced',
            ),
            $orders,
        );
    }

    /**
     * Cancel every open order and report the cancellation to the strategy.
     *
     * @param  list<string>  $recentPrices
     */
    private function cancelAll(
        SimulatedOrderBook $book,
        StrategyInterface $strategy,
        BacktestConfiguration $configuration,
        StrategyConfiguration $strategyConfiguration,
        StrategyState $state,
        InMemoryPortfolio $portfolio,
        Decimal $close,
        array $recentPrices,
        \DateTimeImmutable $at,
    ): StrategyState {
        $market = new MarketSnapshot(
            symbol: $configuration->symbol,
            price: $close,
            bid: $close,
            ask: $close,
            recentPrices: $recentPrices,
            timestamp: $at,
        );

        foreach ($this->cancelEvents($book->cancelAll()) as $event) {
            $state = $strategy->onCancel($this->inputFor(
                $configuration,
                $strategyConfiguration,
                $portfolio,
                $book,
                $state,
                $market,
            ), $event)->state;
        }

        return $state;
    }

    private function dailyLossBreached(
        BacktestRiskGuard $risk,
        InMemoryPortfolio $portfolio,
        Decimal $mark,
        Decimal $dayStartRealized,
    ): bool {
        return $risk->dailyLoss(
            $portfolio->realized,
            $portfolio->unrealized($mark),
            $dayStartRealized,
        )->greaterThanOrEqual($risk->dailyLossLimit());
    }

    private function inputFor(
        BacktestConfiguration $configuration,
        StrategyConfiguration $strategyConfiguration,
        InMemoryPortfolio $portfolio,
        SimulatedOrderBook $book,
        StrategyState $state,
        MarketSnapshot $market,
    ): StrategyInput {
        $openOrders = array_map(
            fn (SimulatedOrder $order) => OpenOrderSnapshot::make(
                $order->clientOrderId,
                $order->side,
                $order->price,
                $order->quantity,
                $order->gridLevelSequence,
            ),
            $book->openOrders(),
        );

        return new StrategyInput(
            market: $market,
            bot: BotSnapshot::make(
                $configuration->botId,
                $portfolio->base,
                $portfolio->quote,
                $portfolio->averageEntry,
                $portfolio->realized,
                $configuration->capitalAllocation,
                $openOrders,
            ),
            config: $strategyConfiguration,
            state: $state,
        );
    }
}
