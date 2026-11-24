<?php

namespace App\Http\Controllers;

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Support\Decimal;
use App\Http\Requests\StoreBacktestRequest;
use App\Http\Resources\BacktestFillResource;
use App\Http\Resources\BacktestRunResource;
use App\Models\BacktestRun;
use App\Services\Backtest\BacktestPeriodValidator;
use App\Services\Backtest\BacktestSnapshotBuilder;
use App\Services\Engine\CommandPublisher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BacktestController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $runs = $request->user()->backtestRuns()
            ->latest('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('Backtests/Index', [
            'runs' => collect(BacktestRunResource::collection($runs)->resolve())
                ->map(fn (array $run) => Arr::except($run, [
                    'config_snapshot',
                    'equity_curve',
                ]))
                ->values(),
            'active' => $runs->contains(fn (BacktestRun $run) => $run->isActive()),
        ]);
    }

    public function create(Request $request): Response
    {
        $bots = $request->user()->bots()
            ->with('strategyConfig')
            ->orderBy('name')
            ->get()
            ->map(fn ($bot) => [
                'id' => $bot->id,
                'name' => $bot->name,
                'exchange' => $bot->exchange,
                'symbol' => $bot->symbol,
                'strategy_key' => $bot->strategy_key,
                'capital_allocation' => $bot->capital_allocation,
                'fee_rate' => $bot->fee_rate,
                'has_configuration' => $bot->strategyConfig !== null,
            ])
            ->values();

        $days = max(1, (int) config('backtest.default_days', 30));

        return Inertia::render('Backtests/Create', [
            'bots' => $bots,
            'timeframes' => Timeframe::configured(),
            'defaults' => [
                'timeframe' => (string) config('backtest.default_timeframe', '15m'),
                'start_at' => now('UTC')->subDays($days)->format('Y-m-d'),
                'end_at' => now('UTC')->format('Y-m-d'),
            ],
            'presets' => [
                ['label' => '7 days', 'days' => 7],
                ['label' => '30 days', 'days' => 30],
                ['label' => '90 days', 'days' => 90],
            ],
            'maxCandles' => (int) config('backtest.max_candles', 200_000),
        ]);
    }

    public function store(
        StoreBacktestRequest $request,
        BacktestSnapshotBuilder $snapshots,
        BacktestPeriodValidator $periods,
        CommandPublisher $commands,
    ): RedirectResponse {
        $bot = $request->user()->bots()->find($request->integer('bot_id'));

        abort_if($bot === null, 403);

        $this->authorize('view', $bot);

        $timeframe = Timeframe::fromConfig((string) $request->validated('timeframe'));
        $startAt = $this->utc((string) $request->validated('start_at'));
        $endAt = $this->utc((string) $request->validated('end_at'));

        $periodError = $periods->validate($timeframe, $startAt, $endAt);

        if ($periodError !== null) {
            throw ValidationException::withMessages([
                $periodError['field'] => $periodError['message'],
            ]);
        }

        try {
            $snapshot = $snapshots->build($bot, $timeframe, $startAt, $endAt);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'bot_id' => $exception->getMessage(),
            ]);
        }

        $run = BacktestRun::query()->create([
            'user_id' => $request->user()->id,
            'bot_id' => $bot->id,
            'name' => (string) ($request->validated('name') ?: $bot->name.' backtest'),
            'exchange' => $bot->exchange,
            'symbol' => $bot->symbol,
            'strategy_key' => $bot->strategy_key,
            'timeframe' => $timeframe->value,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'capital_allocation' => $bot->capital_allocation,
            'fee_rate' => $bot->fee_rate ?? '0',
            'config_snapshot' => $snapshot,
            'status' => BacktestStatus::Pending,
            'progress' => 0,
        ]);

        try {
            $commands->publish('backtest.run', $request->user()->id, $bot->id, [
                'backtest_run_id' => $run->id,
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => BacktestStatus::Failed,
                'error' => 'The backtest job could not be queued.',
                'finished_at' => now('UTC'),
            ])->save();

            return redirect()
                ->route('backtests.show', $run)
                ->withErrors(['backtest' => 'The backtest job could not be queued.']);
        }

        return redirect()
            ->route('backtests.show', $run)
            ->with('status', 'Backtest queued. Results will appear as the replay progresses.');
    }

    public function show(BacktestRun $run): Response
    {
        $this->authorize('view', $run);

        $run->load(['fills' => fn ($query) => $query->orderBy('sequence')->limit(500)]);

        $compareIds = collect();

        if ($run->status === BacktestStatus::Completed) {
            $candidates = BacktestRun::query()
                ->where('user_id', $run->user_id)
                ->whereKeyNot($run->id)
                ->where('status', BacktestStatus::Completed->value)
                ->where('symbol', $run->symbol)
                ->where('timeframe', $run->timeframe)
                ->where('start_at', $run->start_at)
                ->where('end_at', $run->end_at)
                ->latest()
                ->limit(3)
                ->get();

            $compareIds = $candidates
                ->pluck('id')
                ->prepend($run->id)
                ->values();
        }

        return Inertia::render('Backtests/Show', [
            'run' => BacktestRunResource::make($run)->resolve(),
            'fills' => BacktestFillResource::collection($run->fills)->resolve(),
            'compareIds' => $compareIds,
            'assumptions' => [
                'Fills happen at the candle close only: a buy limit fills when the close is at or below the limit, a sell when the close is at or above it. The fill price is the limit, in full, with no slippage.',
                'Every run starts flat with quote inventory equal to the bot capital allocation, zero base inventory, and no open orders. Inventory held by the live bot is not carried in.',
                'Buying is bounded by available quote inventory, including fees. Orders placed during a candle are not eligible to fill until the next candle.',
                'Exchange precision and minimum amount/notional rules are not applied in this version. Orders use the strategy values as produced.',
                'Protective exits are evaluated at the candle close only, not intrabar. A trigger cancels simulated orders and ends the run; inventory is not liquidated.',
                'Results are directional, not predictive. A past range is not a promise of future performance. Each close-only fill observes one close per interval, so results depend on the timeframe; compare runs using the same timeframe and range.',
            ],
        ]);
    }

    public function compare(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:2', 'max:4'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        if (count($ids) < 2) {
            abort(422, 'Select two to four completed runs that you own.');
        }
        $runs = $request->user()->backtestRuns()
            ->whereIn('id', $ids)
            ->where('status', BacktestStatus::Completed->value)
            ->get()
            ->keyBy('id');

        if ($runs->count() !== count($ids)) {
            abort(422, 'Select two to four completed runs that you own.');
        }

        /** @var Collection<int, BacktestRun> $ordered */
        $ordered = collect($ids)->map(fn (int $id) => $runs->get($id))->values();
        $first = $ordered->first();

        foreach ($ordered as $run) {
            if ($run->symbol !== $first->symbol
                || $run->timeframe !== $first->timeframe
                || ! $run->start_at->equalTo($first->start_at)
                || ! $run->end_at->equalTo($first->end_at)) {
                abort(422, 'Selected runs must share the same symbol, timeframe, start, and end.');
            }
        }

        return Inertia::render('Backtests/Compare', [
            'runs' => $ordered->map(function (BacktestRun $run): array {
                $resource = BacktestRunResource::make($run)->resolve();
                $capital = Decimal::of($run->capital_allocation);

                $resource['normalized_curve'] = collect($run->equity_curve ?? [])
                    ->map(fn (array $point) => [
                        'at' => $point['at'] ?? null,
                        'value' => $capital->isZero()
                            ? '0'
                            : Decimal::of((string) ($point['equity'] ?? '0'))->div($capital)->toString(),
                    ])
                    ->values()
                    ->all();

                return $resource;
            })->values(),
        ]);
    }

    public function destroy(BacktestRun $run): RedirectResponse
    {
        $this->authorize('delete', $run);

        $run->delete();

        return redirect()
            ->route('backtests.index')
            ->with('status', 'Backtest deleted.');
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
