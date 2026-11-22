<?php

namespace App\Http\Controllers;

use App\Actions\CreateBot;
use App\Actions\PauseBot;
use App\Actions\StartBot;
use App\Actions\StopBot;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Grid\GridPlanEvaluator;
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
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BotController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, InventoryLedger $ledger): Response
    {
        $bots = $request->user()->bots()
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
        ]);
    }

    public function create(StrategyRegistry $strategies, PresetResolver $presets): Response
    {
        return Inertia::render('Bots/Create', [
            'strategies' => collect($strategies->all())->map(fn ($strategy) => ['key' => $strategy->key()])->values(),
            'exchanges' => array_values(config('trading.exchanges', ['binance'])),
            'presets' => $presets->all(),
            'feeRate' => (string) config('trading.paper.fee_rate', '0'),
        ]);
    }

    public function preview(
        PreviewBotRequest $request,
        GridPlanEvaluator $evaluator,
        MarketDataResolver $markets,
    ): JsonResponse {
        $plan = $evaluator->evaluate($request->gridPlanRequest());
        $limits = $request->effectiveLimits();
        $market = $markets->cached((string) $request->input('exchange'), (string) $request->input('symbol'));

        return response()->json([
            'plan' => $plan->toArray(),
            'errors' => array_map(fn ($problem) => $problem->toArray(), $plan->errors()),
            'warnings' => array_map(fn ($problem) => $problem->toArray(), $plan->warnings()),
            'limits' => $this->limitsPayload($limits),
            'market' => $market === null ? null : $this->marketPayload($market),
            'values' => $request->concreteValues(),
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

    public function store(StoreBotRequest $request, CreateBot $createBot): RedirectResponse
    {
        $bot = $createBot->handle($request->user(), [
            ...$request->validated(),
            'mode' => $request->input('mode', 'paper'),
            'exchange' => (string) $request->validated('exchange'),
            'configuration' => $request->strategyConfiguration(),
        ]);

        return redirect()->route('bots.index')->with('status', "Bot {$bot->name} created.");
    }

    public function show(Bot $bot, InventoryLedger $ledger, RiskLimitService $riskLimits): Response
    {
        $this->authorize('view', $bot);

        $bot->load([
            'activeGrid.levels',
            'orders' => fn ($query) => $query->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])->latest(),
            'fills' => fn ($query) => $query->latest('filled_at')->limit(20),
        ]);

        $grid = $bot->activeGrid;
        $mark = MarkPrice::for($bot)?->toString()
            ?? ($grid === null ? null : $grid->center_price)
            ?? $bot->average_entry_price;

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
                'unrealized' => $ledger->unrealizedPnl($bot, Decimal::of($mark ?? '0'))->toString(),
                'fees_paid' => $bot->fees_paid,
            ],
            'riskLimits' => collect($riskLimits->limitsFor($bot))->map(fn ($limit) => [
                'scope' => $limit->scope->value,
                'max_position_notional' => $limit->max_position_notional,
                'max_open_orders' => $limit->max_open_orders,
                'daily_loss_limit' => $limit->daily_loss_limit,
            ])->values(),
        ]);
    }

    public function edit(
        Bot $bot,
        GridPlanEvaluator $evaluator,
        MarketDataResolver $markets,
        RiskLimitService $riskLimits,
    ): Response {
        $this->authorize('view', $bot);

        $config = $bot->strategyConfig()->first();
        $configuration = $config === null ? [] : $config->configuration;
        $market = $markets->cached($bot->exchange, $bot->symbol);
        $limits = $riskLimits->effectiveForBot($bot);

        $plan = $configuration === []
            ? null
            : $evaluator->evaluate(new GridPlanRequest(
                strategyKey: $bot->strategy_key,
                configuration: $configuration,
                symbol: $bot->symbol,
                capitalAllocation: $bot->capital_allocation,
                baseInventory: $bot->base_inventory,
                marketPrice: $market?->last,
                limits: $limits,
                market: $market,
            ));

        return Inertia::render('Bots/Edit', [
            'bot' => BotSummaryResource::make($bot)->resolve(),
            'configuration' => $configuration,
            'editable' => in_array($bot->status, [BotStatus::Idle, BotStatus::Stopped], true),
            'preview' => $plan === null ? null : [
                'plan' => $plan->toArray(),
                'limits' => $this->limitsPayload($limits),
                'market' => $market === null ? null : $this->marketPayload($market),
            ],
        ]);
    }

    public function update(
        UpdateBotRequest $request,
        Bot $bot,
        ActivityRecorder $activity,
        CancelBotOrders $cancelOrders,
    ): RedirectResponse {
        $this->authorize('update', $bot);

        DB::transaction(function () use ($request, $bot, $activity, $cancelOrders) {
            /** @var Bot $locked */
            $locked = Bot::query()->lockForUpdate()->findOrFail($bot->id);

            abort_unless(in_array($locked->status, [BotStatus::Idle, BotStatus::Stopped], true), 403);

            $attributes = [
                'name' => (string) $request->validated('name'),
                'capital_allocation' => (string) $request->validated('capital_allocation'),
            ];

            if ($request->has('fee_rate')) {
                $attributes['fee_rate'] = (string) ($request->validated('fee_rate') ?? '0');
            }

            $locked->update($attributes);

            $config = $locked->strategyConfig()->firstOrCreate(
                ['bot_id' => $locked->id],
                ['strategy_key' => $locked->strategy_key, 'configuration' => [], 'state_version' => 1],
            );

            $configuration = $request->strategyConfiguration($locked);
            $structuralChange = $config->configuration !== $configuration;

            if ($structuralChange) {
                // The strategies copy structural parameters into their state on
                // initialization, so a changed configuration only takes effect
                // after the state and the old grid are retired.
                $cancelOrders->handle($locked);

                $locked->grids()
                    ->where('status', GridStatus::Active->value)
                    ->update(['status' => GridStatus::Stopped->value]);

                $config->state = null;
            }

            $config->configuration = $configuration;
            $config->save();

            $activity->record('bot.updated', "Bot {$locked->name} updated", $locked, $request->user(), [
                'strategy' => $locked->strategy_key,
                'symbol' => $locked->symbol,
                'strategy_reset' => $structuralChange,
            ]);
        });

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
        } catch (Throwable $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }
    }
}
