<?php

namespace App\Domain\Bot;

use App\Actions\CancelOrder;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Bot\ValueObjects\TickResult;
use App\Domain\Exchange\ConsecutiveFailureTracker;
use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Enums\GridLevelState;
use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\FillProcessor;
use App\Domain\Order\OrderPlacementService;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Order\ValueObjects\FillData;
use App\Domain\Order\ValueObjects\FillResult;
use App\Domain\Risk\DailyLossMonitor;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Risk\RiskEventRecorder;
use App\Domain\Risk\RiskGuard;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\StrategyStateLoader;
use App\Domain\Strategy\StrategyStateSerializer;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BotTickPipeline
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly StrategyStateLoader $stateLoader,
        private readonly StrategyStateSerializer $stateSerializer,
        private readonly RiskGuard $riskGuard,
        private readonly DailyLossMonitor $dailyLoss,
        private readonly OrderPlacementService $placement,
        private readonly OrderStateMachine $stateMachine,
        private readonly FillProcessor $fillProcessor,
        private readonly CancelOrder $cancelOrder,
        private readonly ExchangeClientFactory $exchangeFactory,
        private readonly ConsecutiveFailureTracker $failures,
        private readonly ActivityRecorder $activity,
        private readonly RiskEventRecorder $riskEvents,
        private readonly LiveModeGuard $liveMode,
    ) {}

    public function tick(Bot $bot): TickResult
    {
        $started = microtime(true);
        $bot->refresh();

        if (! $bot->isRunning()) {
            return new TickResult('skipped', durationMs: $this->elapsed($started));
        }

        $bot->forceFill(['last_tick_at' => now()])->save();

        try {
            try {
                $this->liveMode->assertTradingAllowed($bot);
            } catch (LiveTradingDisabledException $exception) {
                $this->pauseForDisabledLiveMode($bot, $exception);

                return new TickResult('skipped', durationMs: $this->elapsed($started));
            }

            $client = $this->exchangeFactory->forBot($bot);
            $marketData = $client->market($bot->symbol);

            $settings = $bot->settings ?? [];
            $recentPrices = $settings['recent_prices'] ?? [];
            $recentPrices = is_array($recentPrices) ? array_values(array_filter($recentPrices, 'is_string')) : [];
            $recentPrices[] = $marketData->last->toString();
            $settings['recent_prices'] = array_slice($recentPrices, -30);
            $settings['mark_price'] = $marketData->last->toString();

            $bot->forceFill(['settings' => $settings])->save();

            $this->dailyLoss->evaluate($bot, $marketData->last);
            $bot->refresh();

            if (! $bot->isRunning()) {
                return new TickResult('skipped', durationMs: $this->elapsed($started));
            }

            $market = MarketSnapshot::make(
                symbol: $marketData->symbol,
                price: $marketData->last,
                bid: $marketData->bid,
                ask: $marketData->ask,
                recentPrices: $settings['recent_prices'],
            );

            /** @var StrategyConfig $config */
            $config = $bot->strategyConfig()->firstOrCreate(
                ['bot_id' => $bot->id],
                ['strategy_key' => $bot->strategy_key, 'configuration' => [], 'state_version' => 1],
            );

            $strategy = $this->strategies->get($bot->strategy_key);
            $state = $this->stateLoader->load($bot);
            $input = $this->inputFor($bot, $market, $config, $state);
            $decision = $state->isNew()
                ? $strategy->initialize($input)
                : $strategy->onTick($input);

            if ($state->isNew()) {
                $decision = $strategy->onTick($this->inputFor($bot, $market, $config, $decision->state));
            }

            $beforeSync = $bot->orders()
                ->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Open->value,
                    OrderStatus::PartiallyFilled->value,
                ])
                ->get()
                ->keyBy('id');

            $gridBefore = $bot->activeGrid?->id;
            $grid = $this->syncGrid($bot, $decision->levels);
            $bot->refresh();

            if ($grid instanceof Grid && $gridBefore !== null && $grid->id !== $gridBefore) {
                // The grid was recreated, which cancels every previous order.
                // Re-evaluate the strategy now that those orders are gone so
                // levels kept from the old grid are not left without orders.
                $decision = $strategy->onTick($this->inputFor($bot, $market, $config, $decision->state));
                $grid = $this->syncGrid($bot, $decision->levels);
                $bot->refresh();
            }

            $cancelEvents = [];

            foreach ($beforeSync as $id => $previous) {
                $current = $bot->orders()->whereKey($id)->first();

                if ($current instanceof Order && $current->status === OrderStatus::Canceled) {
                    $cancelEvents[] = new CancelEvent(
                        $previous->client_order_id,
                        $previous->gridLevel?->sequence,
                        'grid_replaced',
                    );
                }
            }

            foreach ($decision->cancelClientOrderIds as $clientOrderId) {
                $order = $bot->orders()->where('client_order_id', $clientOrderId)->first();

                if ($order instanceof Order) {
                    $this->cancelOrder->handle($order);

                    $cancelEvents[] = new CancelEvent(
                        $order->client_order_id,
                        $order->gridLevel?->sequence,
                        'strategy',
                    );
                }
            }

            if ($cancelEvents !== []) {
                $canceledState = $decision->state;

                foreach ($cancelEvents as $event) {
                    $canceledState = $strategy->onCancel(
                        $this->inputFor($bot, $market, $config, $canceledState),
                        $event,
                    )->state;
                }

                $decision = $decision->withState($canceledState);
            }

            $ordersPlaced = 0;
            $ordersRejected = 0;
            $errors = [];
            $openBefore = $bot->orders()
                ->whereIn('status', [OrderStatus::Open->value, OrderStatus::PartiallyFilled->value])
                ->pluck('id')
                ->all();

            if ($grid instanceof Grid) {
                foreach ($decision->orders as $intent) {
                    $order = $this->placeIntent($bot, $grid, $client, $intent, $marketData);

                    if ($order instanceof Order) {
                        $ordersPlaced++;
                    } else {
                        $ordersRejected++;
                    }
                }
            }

            $placedThisTick = array_values(array_diff(
                $bot->orders()
                    ->whereIn('status', [OrderStatus::Open->value, OrderStatus::PartiallyFilled->value])
                    ->pluck('id')
                    ->all(),
                $openBefore,
            ));

            $fillResult = $this->processPaperFills($bot, $client, $market, $marketData, $strategy, $config, $decision, $placedThisTick);
            $state = $fillResult['state'];
            $errors = array_merge($errors, $fillResult['errors']);

            $this->stateSerializer->persist($config, $state);

            foreach ($decision->events as $event) {
                $this->activity->record(
                    $event['type'],
                    $event['message'],
                    $bot,
                    context: $event['context'] ?? [],
                );
            }

            $stopped = $decision->stop
                || $strategy->shouldStop($this->inputFor($bot->refresh(), $market, $config, $state));

            if ($stopped) {
                $this->stopByStrategy($bot);
            }

            $bot->refresh();
            $bot->forceFill([
                'last_successful_tick_at' => now(),
                'last_error' => $stopped ? 'The strategy requested that the bot stop.' : null,
            ])->save();

            $this->failures->recordSuccess($bot);

            $result = new TickResult(
                status: 'ok',
                marketPrice: $market->priceString(),
                ordersPlaced: $ordersPlaced,
                fills: $fillResult['fills'],
                ordersRejected: $ordersRejected,
                errors: $errors,
                durationMs: $this->elapsed($started),
            );

            Log::info('bot.tick', [
                'bot_id' => $bot->id,
                'symbol' => $bot->symbol,
                'status' => $result->status,
                'market_price' => $result->marketPrice,
                'orders_placed' => $result->ordersPlaced,
                'fills' => $result->fills,
                'orders_rejected' => $result->ordersRejected,
                'duration_ms' => $result->durationMs,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->failures->recordFailure($bot, $exception, 'tick');

            $bot->refresh();
            $bot->forceFill([
                'last_tick_at' => $bot->last_tick_at ?? now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            Log::error('bot.tick_failed', [
                'bot_id' => $bot->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'duration_ms' => $this->elapsed($started),
            ]);

            return new TickResult(
                status: 'failed',
                errors: [$exception->getMessage()],
                durationMs: $this->elapsed($started),
            );
        }
    }

    /**
     * Route a confirmed live fill through the strategy and place the resulting
     * orders. Paper fills use processPaperFills() which applies the same logic.
     */
    public function recordFill(
        Bot $bot,
        ExchangeClient $client,
        MarketData $marketData,
        Order $order,
        FillResult $result,
    ): void {
        if ($result->duplicate) {
            return;
        }

        $bot->refresh();
        $recentPrices = $bot->settings['recent_prices'] ?? [];
        $market = MarketSnapshot::make(
            symbol: $marketData->symbol,
            price: $marketData->last,
            bid: $marketData->bid,
            ask: $marketData->ask,
            recentPrices: is_array($recentPrices) ? array_values(array_filter($recentPrices, 'is_string')) : [],
        );

        /** @var StrategyConfig $config */
        $config = $bot->strategyConfig()->firstOrCreate(
            ['bot_id' => $bot->id],
            ['strategy_key' => $bot->strategy_key, 'configuration' => [], 'state_version' => 1],
        );

        if ($config->state === null) {
            return;
        }

        $strategy = $this->strategies->get($bot->strategy_key);
        $state = $this->stateLoader->load($bot);
        $order->loadMissing('gridLevel');

        /** @var GridLevel|null $level */
        $level = $order->gridLevel;

        $decision = $strategy->onFill(
            $this->inputFor($bot, $market, $config, $state),
            FillEvent::make(
                exchangeFillId: $result->fill->exchange_fill_id,
                side: $order->side,
                price: $result->fill->price,
                quantity: $result->fill->quantity,
                fee: $result->fill->fee,
                clientOrderId: $order->client_order_id,
                gridLevelSequence: $level?->sequence,
            ),
        );

        $this->stateSerializer->persist($config, $decision->state);
        $grid = $this->syncGrid($bot, $decision->levels);

        if (! $grid instanceof Grid) {
            return;
        }

        $intents = $decision->orders;

        if ($result->opposite instanceof OrderIntent) {
            $intents[] = $result->opposite;
        }

        foreach ($intents as $intent) {
            $this->placeIntent($bot, $grid, $client, $intent, $marketData);
        }
    }

    private function inputFor(Bot $bot, MarketSnapshot $market, StrategyConfig $config, \App\Domain\Strategy\ValueObjects\StrategyState $state): StrategyInput
    {
        return new StrategyInput(
            market: $market,
            bot: $this->snapshot($bot),
            config: new StrategyConfiguration($bot->strategy_key, $config->configuration ?? []),
            state: $state,
        );
    }

    private function snapshot(Bot $bot): BotSnapshot
    {
        $openOrders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->with('gridLevel')
            ->get()
            ->map(fn (Order $order) => OpenOrderSnapshot::make(
                $order->client_order_id,
                $order->side,
                $order->price,
                $order->quantity,
                $order->gridLevel?->sequence,
            ))
            ->all();

        return BotSnapshot::make(
            $bot->id,
            $bot->base_inventory,
            $bot->quote_inventory,
            $bot->average_entry_price,
            $bot->realized_pnl,
            $bot->capital_allocation,
            $openOrders,
        );
    }

    /** @param list<GridLevelPlan> $levels */
    private function syncGrid(Bot $bot, array $levels): ?Grid
    {
        $active = $bot->activeGrid()->with('levels')->first();

        if ($levels === []) {
            return $active;
        }

        if ($active instanceof Grid && $this->levelsMatch($active, $levels)) {
            return $active;
        }

        return DB::transaction(function () use ($bot, $active, $levels) {
            if ($active instanceof Grid) {
                foreach ($active->orders()->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Open->value,
                    OrderStatus::PartiallyFilled->value,
                ])->get() as $order) {
                    $this->cancelOrder->handle($order);
                }

                $active->forceFill(['status' => GridStatus::Stopped])->save();
            }

            $first = $levels[0]->price;
            $last = $levels[count($levels) - 1]->price;
            $center = $first->add($last)->div('2');
            $spacing = count($levels) > 1 ? $levels[1]->price->sub($levels[0]->price)->abs() : '0';

            $grid = Grid::create([
                'bot_id' => $bot->id,
                'center_price' => $center->toString(),
                'spacing_type' => $bot->strategy_key === 'dynamic-grid'
                    ? GridSpacingType::Dynamic
                    : GridSpacingType::Arithmetic,
                'level_count' => count($levels),
                'spacing_value' => $spacing,
                'status' => GridStatus::Active,
            ]);

            foreach ($levels as $plan) {
                GridLevel::create([
                    'grid_id' => $grid->id,
                    'sequence' => $plan->sequence,
                    'price' => $plan->price->toString(),
                    'side' => $plan->side,
                    'state' => GridLevelState::Idle,
                ]);
            }

            return $grid->load('levels');
        });
    }

    /** @param list<GridLevelPlan> $levels */
    private function levelsMatch(Grid $grid, array $levels): bool
    {
        $existing = $grid->levels;

        if ($existing->count() !== count($levels)) {
            return false;
        }

        foreach ($levels as $index => $plan) {
            $level = $existing[$index];

            if ($level->sequence !== $plan->sequence
                || ! Decimal::of($level->price)->equals($plan->price)
                || $level->side !== $plan->side) {
                return false;
            }
        }

        return true;
    }

    private function pauseForDisabledLiveMode(Bot $bot, LiveTradingDisabledException $exception): void
    {
        $bot->forceFill([
            'status' => BotStatus::Paused,
            'last_error' => $exception->getMessage(),
        ])->save();

        $this->activity->record(
            'bot.live_trading_disabled',
            'Live trading was disabled; the bot was paused.',
            $bot,
            severity: 'error',
        );

        $this->riskEvents->record(
            'risk.live_trading_disabled',
            $exception->getMessage(),
            $bot,
            severity: RiskSeverity::Critical,
        );

        $orders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->get();

        foreach ($orders as $order) {
            try {
                $this->cancelOrder->handle($order);
            } catch (Throwable $cancelError) {
                $this->riskEvents->record(
                    'risk.live_cancel_failed',
                    'A live order could not be canceled after live trading was disabled.',
                    $bot,
                    context: [
                        'order_id' => $order->id,
                        'client_order_id' => $order->client_order_id,
                        'error' => $cancelError->getMessage(),
                    ],
                    severity: RiskSeverity::Critical,
                );
            }
        }
    }

    private function stopByStrategy(Bot $bot): void
    {
        $bot->forceFill([
            'status' => BotStatus::Paused,
            'last_error' => 'The strategy requested that the bot stop.',
        ])->save();

        $this->activity->record('bot.strategy_stop', 'Strategy requested a pause.', $bot, severity: 'warning');

        $orders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->get();

        foreach ($orders as $order) {
            try {
                $this->cancelOrder->handle($order);
            } catch (Throwable $exception) {
                $this->riskEvents->record(
                    'risk.strategy_stop_cancel_failed',
                    'An order could not be canceled after the strategy requested a stop.',
                    $bot,
                    context: [
                        'order_id' => $order->id,
                        'client_order_id' => $order->client_order_id,
                        'error' => $exception->getMessage(),
                    ],
                    severity: RiskSeverity::Critical,
                );
            }
        }
    }

    private function placeIntent(Bot $bot, Grid $grid, ExchangeClient $client, OrderIntent $intent, MarketData $marketData): ?Order
    {
        $level = $grid->levels->firstWhere('sequence', $intent->gridLevelSequence);

        if (! $level instanceof GridLevel) {
            $this->riskEvents->record(
                'order.level_missing',
                'Order intent referenced a grid level that does not exist.',
                $bot,
                context: ['sequence' => $intent->gridLevelSequence],
            );

            return null;
        }

        $price = Decimal::of($intent->price)->round($marketData->pricePrecision);
        $quantity = Decimal::of($intent->quantity)->round($marketData->amountPrecision);

        if (! $quantity->isPositive() || $quantity->lessThan($marketData->minAmount)) {
            $this->riskEvents->record(
                'order.min_amount',
                'Order quantity is below the exchange minimum amount.',
                $bot,
                context: [
                    'sequence' => $intent->gridLevelSequence,
                    'quantity' => $quantity->toString(),
                    'min_amount' => $marketData->minAmount->toString(),
                ],
                severity: RiskSeverity::Warning,
            );

            return null;
        }

        if ($price->mul($quantity)->lessThan($marketData->minNotional)) {
            $this->riskEvents->record(
                'order.min_notional',
                'Order notional is below the exchange minimum.',
                $bot,
                context: [
                    'sequence' => $intent->gridLevelSequence,
                    'notional' => $price->mul($quantity)->toString(),
                    'min_notional' => $marketData->minNotional->toString(),
                ],
                severity: RiskSeverity::Warning,
            );

            return null;
        }

        $decision = $this->riskGuard->checkOrder($bot, $intent->side, $price, $quantity);

        if (! $decision->allowed) {
            return null;
        }

        $existing = $bot->orders()
            ->where('grid_level_id', $level->id)
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->first();

        if ($existing instanceof Order) {
            return $existing;
        }

        $cycle = $bot->orders()->where('grid_level_id', $level->id)->count() + 1;
        $order = $this->placement->request($bot, $grid, $level, $intent->side, $price, $quantity, $cycle);

        if (! $order->status->canTransitionTo(OrderStatus::Open)) {
            return $order;
        }

        if ($bot->isLive()) {
            try {
                $exchangeOrder = $client->placeLimitOrder(
                    $bot,
                    $bot->symbol,
                    $intent->side,
                    $intent->price,
                    $intent->quantity,
                    $order->client_order_id,
                );

                $this->stateMachine->transition($order, OrderStatus::Open, [
                    'exchange_order_id' => $exchangeOrder->exchangeOrderId,
                ]);
                $level->forceFill(['state' => GridLevelState::Open])->save();

                return $order->refresh();
            } catch (ExchangeException $exception) {
                if ($exception->isRetryable() || $exception->type === ExchangeErrorType::Unknown) {
                    $order->forceFill(['last_error' => $exception->getMessage()])->save();

                    $this->riskEvents->record(
                        'order.placement_uncertain',
                        'The exchange outcome for a live order is unknown; the order stays pending for reconciliation.',
                        $bot,
                        context: [
                            'client_order_id' => $order->client_order_id,
                            'error' => $exception->getMessage(),
                            'type' => $exception->type->value,
                        ],
                        severity: RiskSeverity::Warning,
                    );

                    return $order->refresh();
                }

                $this->stateMachine->transition($order, OrderStatus::Rejected, [
                    'last_error' => $exception->getMessage(),
                ]);

                $this->riskEvents->record(
                    'order.rejected',
                    'The exchange rejected a live order.',
                    $bot,
                    context: [
                        'client_order_id' => $order->client_order_id,
                        'error' => $exception->getMessage(),
                        'type' => $exception->type->value,
                    ],
                );

                return null;
            }
        }

        $this->stateMachine->transition($order, OrderStatus::Open, [
            'exchange_order_id' => 'paper-'.$order->client_order_id,
        ]);
        $level->forceFill(['state' => GridLevelState::Open])->save();

        return $order->refresh();
    }

    /**
     * @param  list<int>  $excludeOrderIds
     * @return array{state: \App\Domain\Strategy\ValueObjects\StrategyState, fills: int, errors: list<string>}
     */
    private function processPaperFills(
        Bot $bot,
        ExchangeClient $client,
        MarketSnapshot $market,
        \App\Domain\Exchange\ValueObjects\MarketData $marketData,
        \App\Domain\Strategy\Contracts\StrategyInterface $strategy,
        StrategyConfig $config,
        StrategyDecision $decision,
        array $excludeOrderIds = [],
    ): array {
        if (! $client instanceof PaperExchangeClient) {
            return ['state' => $decision->state, 'fills' => 0, 'errors' => []];
        }

        $state = $decision->state;
        $fills = 0;
        $errors = [];

        $orders = $bot->orders()
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::PartiallyFilled->value])
            ->when($excludeOrderIds !== [], fn ($query) => $query->whereNotIn('id', $excludeOrderIds))
            ->orderBy('id')
            ->get();

        foreach ($client->qualifyingFills($marketData, $orders, $bot->fee_rate ?? '0') as $paperFill) {
            $order = Order::query()->find($paperFill->orderId);

            if (! $order instanceof Order) {
                continue;
            }

            try {
                /** @var FillResult $result */
                $result = $this->fillProcessor->process($bot, $order, FillData::make(
                    exchange: 'paper',
                    exchangeFillId: 'paper-'.$order->id.'-'.$order->filled_quantity,
                    price: $paperFill->price,
                    quantity: $paperFill->quantity,
                    fee: $paperFill->fee,
                    feeCurrency: $paperFill->feeCurrency,
                ));
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();

                break;
            }

            if (! $result->duplicate) {
                $fills++;
            }

            $bot->refresh();

            /** @var GridLevel|null $level */
            $level = $order->gridLevel;
            $fillDecision = $strategy->onFill(
                $this->inputFor($bot, $market, $config, $state),
                FillEvent::make(
                    exchangeFillId: 'paper-'.$order->id,
                    side: $paperFill->side,
                    price: $paperFill->price,
                    quantity: $paperFill->quantity,
                    fee: $paperFill->fee,
                    clientOrderId: $order->client_order_id,
                    gridLevelSequence: $level?->sequence,
                ),
            );

            $state = $fillDecision->state;
            $grid = $this->syncGrid($bot, $fillDecision->levels);

            if ($grid instanceof Grid) {
                $intents = $fillDecision->orders;

                if ($result->opposite instanceof OrderIntent) {
                    $intents[] = $result->opposite;
                }

                foreach ($intents as $intent) {
                    $this->placeIntent($bot, $grid, $client, $intent, $marketData);
                }
            }

            $bot->refresh();

            if (! $bot->isRunning()) {
                break;
            }
        }

        return ['state' => $state, 'fills' => $fills, 'errors' => $errors];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
