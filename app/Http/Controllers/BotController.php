<?php

namespace App\Http\Controllers;

use App\Actions\CancelBotOrder;
use App\Actions\CreateBot;
use App\Actions\PauseBot;
use App\Actions\StartBot;
use App\Actions\StopBot;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Backtest\BacktestComparison;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Bot\BotEditDiff;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Exceptions\GridPlanUnavailableException;
use App\Domain\Grid\PresetResolver;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Portfolio\MarkPrice;
use App\Domain\Risk\Exceptions\LiveConfirmationRequiredException;
use App\Domain\Risk\RiskLimitService;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;
use App\Http\Requests\PreviewBotRequest;
use App\Http\Requests\StoreBotRequest;
use App\Http\Requests\UpdateBotRequest;
use App\Http\Resources\BotSummaryResource;
use App\Models\ActivityLog;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskEvent;
use App\Services\Backtest\BacktestBotPrefill;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use App\Services\Engine\GridPlanClient;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BotController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, InventoryLedger $ledger): Response
    {
        $archived = $request->boolean('archived');
        $query = $request->user()->bots();

        if ($archived) {
            $query->onlyTrashed();
        }

        $bots = $query
            ->with('activeGrid')
            ->withCount(['orders as open_orders_count' => fn ($query) => $query->whereIn('status', ['pending', 'open', 'partially_filled'])])
            ->latest()
            ->get();

        foreach ($bots as $bot) {
            $mark = MarkPrice::for($bot);
            $bot->unrealized_pnl = $mark === null
                ? '0'
                : $ledger->unrealizedPnl($bot, $mark)->toString();
        }

        return Inertia::render('Bots/Index', [
            'bots' => BotSummaryResource::collection($bots)->resolve(),
            'archived' => $archived,
        ]);
    }

    public function create(
        Request $request,
        StrategyRegistry $strategies,
        PresetResolver $presets,
        BacktestBotPrefill $prefill,
    ): Response {
        $sourceRun = null;
        $initial = null;

        if ($request->filled('source_backtest_run_id')) {
            $run = BacktestRun::query()
                ->where('user_id', $request->user()?->id)
                ->findOrFail((int) $request->query('source_backtest_run_id'));

            abort_unless($run->status === BacktestStatus::Completed, 404);
            abort_unless($prefill->supports($run->config_snapshot), 404);

            $snapshotBot = $run->config_snapshot['bot'] ?? null;
            abort_unless(is_array($snapshotBot), 404);

            $exchange = $snapshotBot['exchange'] ?? null;
            abort_unless(
                is_string($exchange)
                    && in_array($exchange, (array) config('trading.exchanges', []), true),
                404,
            );

            $symbol = $snapshotBot['symbol'] ?? null;
            abort_unless(
                is_string($symbol)
                    && preg_match('/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/', $symbol) === 1,
                404,
            );

            $sourceRun = [
                'id' => $run->id,
                'name' => $run->name,
                'exchange' => $run->exchange,
                'symbol' => $run->symbol,
                'strategy_key' => $run->strategy_key,
                'timeframe' => $run->timeframe,
                'start_at' => $run->start_at?->toIso8601String(),
                'end_at' => $run->end_at?->toIso8601String(),
            ];

            $initial = $prefill->fromRun($run);
        }

        return Inertia::render('Bots/Create', [
            'strategies' => array_map(fn (string $key) => ['key' => $key], $strategies->keys()),
            'exchanges' => array_values(config('trading.exchanges', ['binance'])),
            'presets' => $presets->all(),
            'feeRate' => (string) config('trading.paper.fee_rate', '0'),
            'sourceRun' => $sourceRun,
            'initial' => $initial,
        ]);
    }

    public function preview(
        PreviewBotRequest $request,
        GridPlanClient $plans,
        MarketDataResolver $markets,
    ): JsonResponse {
        $bot = $request->previewBot();
        $proposed = $request->proposedEdit();
        $diff = $bot !== null && $proposed !== null ? BotEditDiff::between($bot, $proposed) : [];

        try {
            $plan = $plans->evaluate($request->gridPlanRequest());
        } catch (GridPlanUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'diff' => $diff,
            ], 503);
        }

        $limits = $request->effectiveLimits();
        $market = $markets->cached((string) $request->input('exchange'), (string) $request->input('symbol'));

        return response()->json([
            'plan' => $plan->toArray(),
            'errors' => array_map(fn ($problem) => $problem->toArray(), $plan->errors()),
            'warnings' => array_map(fn ($problem) => $problem->toArray(), $plan->warnings()),
            'limits' => $this->limitsPayload($limits),
            'market' => $market === null ? null : $this->marketPayload($market),
            'values' => $request->concreteValues(),
            'diff' => $diff,
        ]);
    }

    public function marketData(Request $request, MarketDataResolver $markets): JsonResponse
    {
        $data = $request->validate([
            'exchange' => ['required', 'string', 'in:'.implode(',', config('trading.exchanges', ['binance']))],
            'symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/'],
        ]);

        $market = $markets->resolve((string) $data['exchange'], (string) $data['symbol']);

        return response()->json([
            'available' => $market !== null,
            'market' => $market === null ? null : $this->marketPayload($market),
        ]);
    }

    /** @param array<string, mixed> $limits @return array<string, mixed> */
    private function limitsPayload(array $limits): array
    {
        return [
            'max_position_notional' => $limits['max_position_notional']->toString(),
            'max_open_orders' => $limits['max_open_orders'],
            'daily_loss_limit' => $limits['daily_loss_limit']->toString(),
        ];
    }

    /** @return array<string, mixed> */
    private function marketPayload(MarketData $market): array
    {
        return [
            'symbol' => $market->symbol,
            'last' => $market->last->toString(),
            'bid' => $market->bid?->toString(),
            'ask' => $market->ask?->toString(),
            'price_precision' => $market->pricePrecision,
            'amount_precision' => $market->amountPrecision,
            'min_amount' => $market->minAmount->toString(),
            'min_notional' => $market->minNotional->toString(),
            'timestamp' => $market->timestamp?->format(DATE_ATOM),
        ];
    }

    public function store(
        StoreBotRequest $request,
        CreateBot $createBot,
        BacktestBotPrefill $prefill,
    ): RedirectResponse {
        $data = $request->validated();

        if (($data['source_backtest_run_id'] ?? null) !== null) {
            $run = BacktestRun::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail((int) $data['source_backtest_run_id']);

            $derived = $prefill->fromRun($run);
            unset($derived['name']);

            $data = array_merge($data, $derived, [
                'preset_key' => null,
                'live_confirmation' => null,
            ]);
        }

        $bot = $createBot->handle($request->user(), [
            ...$data,
            'mode' => $data['mode'] ?? 'paper',
            'exchange' => (string) ($data['exchange'] ?? config('trading.default_exchange', 'binance')),
            'configuration' => $request->strategyConfiguration(),
        ]);

        return redirect()->route('bots.index')->with('status', "Bot {$bot->name} created.");
    }

    public function destroy(
        Request $request,
        Bot $bot,
        ActivityRecorder $activity,
        EngineHeartbeat $heartbeat,
    ): RedirectResponse {
        $this->authorize('delete', $bot);

        $archived = DB::transaction(function () use ($request, $bot, $activity, $heartbeat): bool {
            /** @var Bot $locked */
            $locked = Bot::query()->lockForUpdate()->findOrFail($bot->id);

            if ($locked->status !== BotStatus::Stopped || $locked->desired_status !== BotStatus::Stopped) {
                return false;
            }

            $hasOpenOrders = $locked->orders()
                ->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Open->value,
                    OrderStatus::PartiallyFilled->value,
                ])
                ->exists();

            if ($hasOpenOrders) {
                return false;
            }

            if ($locked->isLive() && ! $heartbeat->available()) {
                return false;
            }

            $locked->delete();

            $activity->recordUser('bot.archived', "Bot {$locked->name} archived", $locked, $request->user());

            return true;
        });

        if (! $archived) {
            return back()->withErrors([
                'action' => 'Stop the bot, cancel any open orders, and make sure the engine is available before archiving it.',
            ]);
        }

        return redirect()->route('bots.index')->with('status', "Bot {$bot->name} archived.");
    }

    public function restore(Request $request, Bot $bot, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('restore', $bot);

        abort_unless($bot->trashed(), 404);

        DB::transaction(function () use ($request, $bot, $activity): void {
            $restored = Bot::onlyTrashed()
                ->whereKey($bot->getKey())
                ->update(['deleted_at' => null]);

            abort_if($restored === 0, 404);

            $activity->recordUser('bot.restored', "Bot {$bot->name} restored", $bot, $request->user());
        });

        return redirect()->route('bots.index')->with('status', "Bot {$bot->name} restored.");
    }

    public function show(
        Bot $bot,
        InventoryLedger $ledger,
        RiskLimitService $riskLimits,
        EngineHeartbeat $heartbeat,
    ): Response {
        $this->authorize('view', $bot);

        $bot->load([
            'activeGrid.levels',
            'orders' => fn ($query) => $query->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])->latest(),
            'fills' => fn ($query) => $query->latest('filled_at')->limit(20),
            'sourceBacktest',
            'strategyConfig',
        ]);
        $bot->loadCount('fills');

        $grid = $bot->activeGrid;
        $mark = MarkPrice::for($bot)?->toString()
            ?? ($grid === null ? null : $grid->center_price)
            ?? $bot->average_entry_price;
        $unrealized = $ledger->unrealizedPnl($bot, Decimal::of($mark ?? '0'));

        return Inertia::render('Bots/Show', [
            'bot' => BotSummaryResource::make($bot)->resolve(),
            'grid' => $grid === null ? null : [
                'id' => $grid->id,
                'center_price' => $grid->center_price,
                'spacing_type' => $grid->spacing_type->value,
                'spacing_value' => $grid->spacing_value,
                'status' => $grid->status->value,
                'levels' => $grid->levels->map(fn ($level) => [
                    'id' => $level->id,
                    'sequence' => $level->sequence,
                    'price' => $level->price,
                    'side' => $level->side->value,
                    'state' => $level->state->value,
                ])->values(),
            ],
            'openOrders' => $bot->orders->map(fn ($order) => [
                'id' => $order->id,
                'client_order_id' => $order->client_order_id,
                'grid_level_id' => $order->grid_level_id,
                'side' => $order->side->value,
                'type' => $order->type->value,
                'price' => $order->price,
                'quantity' => $order->quantity,
                'filled_quantity' => $order->filled_quantity,
                'status' => $order->status->value,
                'opened_at' => $order->opened_at?->toIso8601String(),
            ])->values(),
            'recentFills' => $bot->fills->map(fn ($fill) => [
                'id' => $fill->id,
                'side' => $fill->side->value,
                'price' => $fill->price,
                'quantity' => $fill->quantity,
                'fee' => $fill->fee,
                'filled_at' => $fill->filled_at?->toIso8601String(),
            ])->values(),
            'inventory' => [
                'base' => $bot->base_inventory,
                'quote' => $bot->quote_inventory,
                'average_entry_price' => $bot->average_entry_price,
                'reference_price' => $mark,
            ],
            'pnl' => [
                'realized' => $bot->realized_pnl,
                'unrealized' => $unrealized->toString(),
                'fees_paid' => $bot->fees_paid,
            ],
            'backtestComparison' => $bot->sourceBacktest === null ? null : BacktestComparison::make(
                $bot,
                $bot->sourceBacktest,
                $unrealized,
                (int) $bot->fills_count,
            )->toArray(),
            'riskLimits' => collect($riskLimits->limitsFor($bot))->map(fn ($limit) => [
                'scope' => $limit->scope->value,
                'max_position_notional' => $limit->max_position_notional,
                'max_open_orders' => $limit->max_open_orders,
                'daily_loss_limit' => $limit->daily_loss_limit,
            ])->values(),
            'canArchive' => $bot->status === BotStatus::Stopped
                && $bot->desired_status === BotStatus::Stopped
                && $bot->orders->isEmpty()
                && (! $bot->isLive() || $heartbeat->available()),
        ]);
    }

    public function edit(
        Bot $bot,
        GridPlanClient $plans,
        MarketDataResolver $markets,
        RiskLimitService $riskLimits,
    ): Response {
        $this->authorize('view', $bot);

        $config = $bot->strategyConfig()->first();
        $configuration = $config === null ? [] : $config->configuration;
        $market = $markets->cached($bot->exchange, $bot->symbol);
        $limits = $riskLimits->effectiveForBot($bot);

        $plan = null;
        $previewError = null;

        if ($configuration !== []) {
            try {
                $plan = $plans->evaluate(new GridPlanRequest(
                    strategyKey: $bot->strategy_key,
                    configuration: $configuration,
                    symbol: $bot->symbol,
                    capitalAllocation: $bot->capital_allocation,
                    baseInventory: $bot->base_inventory,
                    marketPrice: $market?->last,
                    limits: $limits,
                    market: $market,
                ));
            } catch (GridPlanUnavailableException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        $runningPaper = $bot->status === BotStatus::Running && ! $bot->isLive();
        $editable = $runningPaper
            || in_array($bot->status, [BotStatus::Idle, BotStatus::Stopped], true);

        return Inertia::render('Bots/Edit', [
            'bot' => BotSummaryResource::make($bot)->resolve(),
            'configuration' => $configuration,
            'editable' => $editable,
            'runningPaper' => $runningPaper,
            'requiresConfirmation' => $runningPaper,
            'diff' => [],
            'preview' => $configuration === [] ? null : [
                'plan' => $plan?->toArray(),
                'limits' => $this->limitsPayload($limits),
                'market' => $market === null ? null : $this->marketPayload($market),
                'error' => $previewError,
            ],
        ]);
    }

    public function update(
        UpdateBotRequest $request,
        Bot $bot,
        ActivityRecorder $activity,
        CommandPublisher $commands,
    ): RedirectResponse {
        $this->authorize('update', $bot);

        $noop = false;
        $reconcile = false;
        $strategyReset = false;
        $diff = [];

        DB::transaction(function () use ($request, $bot, $activity, &$noop, &$reconcile, &$strategyReset, &$diff) {
            /** @var Bot $locked */
            $locked = Bot::query()->lockForUpdate()->findOrFail($bot->id);

            abort_unless($request->user()->can('update', $locked), 403);

            $runningPaper = $locked->status === BotStatus::Running && ! $locked->isLive();

            if ($runningPaper && ! $request->boolean('confirm_running_edit')) {
                throw ValidationException::withMessages([
                    'confirm_running_edit' => 'Confirm that open orders will be cancelled and the ladder rebuilt before saving changes to a running paper bot.',
                ]);
            }

            $feeRate = $request->has('fee_rate')
                ? (string) ($request->validated('fee_rate') ?? '0')
                : (string) $locked->fee_rate;

            $proposed = [
                'name' => trim((string) $request->validated('name')),
                'capital_allocation' => (string) $request->validated('capital_allocation'),
                'fee_rate' => $feeRate,
                'configuration' => $request->strategyConfiguration($locked),
            ];

            $config = $locked->strategyConfig()->first();
            $locked->setRelation('strategyConfig', $config);

            $currentConfiguration = data_get($config, 'configuration', []);
            if (! is_array($currentConfiguration)) {
                $currentConfiguration = [];
            }

            $configurationChanged = BotEditDiff::configurationsDiffer($currentConfiguration, $proposed['configuration']);

            $diff = BotEditDiff::between($locked, $proposed);

            $strategyStructural = $configurationChanged;
            $capitalChange = false;

            foreach ($diff as $change) {
                if ($change['key'] === 'capital_allocation') {
                    $capitalChange = true;
                }

                if (str_starts_with($change['key'], 'strategy.')) {
                    $strategyStructural = true;
                }
            }

            if ($diff === [] && ! $locked->reconcile_pending && ! $locked->strategy_reset_pending) {
                $noop = true;

                return;
            }

            if ($diff !== []) {
                $attributes = [
                    'name' => $proposed['name'],
                    'capital_allocation' => $proposed['capital_allocation'],
                ];

                if ($request->has('fee_rate')) {
                    $attributes['fee_rate'] = $feeRate;
                }

                $locked->update($attributes);
            }

            if ($strategyStructural) {
                $config ??= $locked->strategyConfig()->firstOrCreate(
                    ['bot_id' => $locked->id],
                    ['strategy_key' => $locked->strategy_key, 'configuration' => [], 'state_version' => 1],
                );

                $config->configuration = $proposed['configuration'];
                $config->save();
            }

            if ($runningPaper) {
                if ($strategyStructural || $capitalChange || $locked->reconcile_pending) {
                    $locked->forceFill(['reconcile_pending' => true])->save();
                    $reconcile = true;
                }
            } else {
                if ($strategyStructural) {
                    $locked->forceFill(['strategy_reset_pending' => true])->save();
                }

                $strategyReset = $strategyStructural || (bool) $locked->strategy_reset_pending;
            }

            if ($diff !== [] || $reconcile || $strategyReset) {
                $activity->recordUser('bot.updated', "Bot {$locked->name} updated", $locked, $request->user(), [
                    'strategy' => $locked->strategy_key,
                    'symbol' => $locked->symbol,
                    'diff' => $diff,
                    'strategy_reset' => $strategyReset || $reconcile,
                    'reconcile' => $reconcile,
                ]);
            }
        });

        if ($noop) {
            return redirect()->route('bots.show', $bot)->with('status', 'No changes.');
        }

        if ($reconcile) {
            try {
                $commands->publish('bot.config', $request->user()->id, $bot->id, [
                    'strategy_reset' => true,
                    'reconcile' => true,
                ]);
            } catch (Throwable $exception) {
                Log::warning('bot.config reconcile publish failed', [
                    'bot_id' => $bot->id,
                    'error_type' => $exception::class,
                ]);

                return redirect()
                    ->route('bots.show', $bot)
                    ->withErrors(['engine' => 'The bot was saved, but the engine could not be notified. The engine will retry the reconcile.']);
            }

            return redirect()
                ->route('bots.show', $bot)
                ->with('status', 'Bot updated. Open orders will be cancelled and the ladder rebuilt.');
        }

        if ($strategyReset) {
            try {
                $commands->publish('bot.config', $request->user()->id, $bot->id, ['strategy_reset' => true]);
            } catch (Throwable $exception) {
                Bot::query()
                    ->whereKey($bot->id)
                    ->update(['strategy_reset_pending' => true]);

                Log::warning('bot.config command publish failed', [
                    'bot_id' => $bot->id,
                    'error_type' => $exception::class,
                ]);

                return redirect()
                    ->route('bots.show', $bot)
                    ->withErrors(['engine' => 'The bot was saved, but the engine could not be notified. Retry the update to reset the runtime strategy state.']);
            }
        }

        return redirect()->route('bots.show', $bot)->with('status', 'Bot updated.');
    }

    public function start(Request $request, Bot $bot, StartBot $startBot): RedirectResponse
    {
        $this->authorize('control', $bot);

        try {
            $startBot->handle($bot, LiveStartConfirmation::fromArray($request->only('confirmation')));

            return back()->with('status', 'Bot started.');
        } catch (LiveConfirmationRequiredException $exception) {
            return back()->withErrors(['confirmation' => $exception->getMessage()]);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (Throwable $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }
    }

    public function activity(Bot $bot): Response
    {
        $this->authorize('view', $bot);

        return Inertia::render('Bots/Activity', [
            'bot' => BotSummaryResource::make($bot)->resolve(),
            'activityLogs' => $bot->activityLogs()
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'type' => $log->type,
                    'severity' => $log->severity,
                    'message' => $log->message,
                    'context' => $log->context,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
            'riskEvents' => $bot->riskEvents()
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (RiskEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'severity' => $event->severity,
                    'message' => $event->message,
                    'context' => $event->context,
                    'created_at' => $event->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function pause(Bot $bot, PauseBot $pauseBot): RedirectResponse
    {
        $this->authorize('control', $bot);

        try {
            $pauseBot->handle($bot);

            return back()->with('status', 'Bot paused.');
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (Throwable $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }
    }

    public function stop(Bot $bot, StopBot $stopBot): RedirectResponse
    {
        $this->authorize('control', $bot);

        try {
            $stopBot->handle($bot);

            return back()->with('status', 'Bot stopped.');
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (Throwable $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }
    }

    public function cancelOrder(Bot $bot, Order $order, CancelBotOrder $cancelOrder): RedirectResponse
    {
        $this->authorize('control', $bot);
        abort_unless($order->bot_id === $bot->id, 404);

        try {
            $published = $cancelOrder->handle($bot, $order);

            return back()->with(
                'status',
                $published ? 'Order cancellation queued.' : 'The order is already closed.',
            );
        } catch (Throwable $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }
    }
}
