<?php

namespace App\Http\Controllers;

use App\Actions\CreateBacktestSweep;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\Enums\Timeframe;
use App\Http\Requests\StoreBacktestSweepRequest;
use App\Http\Resources\BacktestSweepResource;
use App\Http\Resources\BacktestSweepRunResource;
use App\Models\BacktestSweep;
use App\Services\Backtest\BacktestPeriodValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BacktestSweepController extends Controller
{
    public function index(Request $request): Response
    {
        $sweeps = $request->user()->backtestSweeps()
            ->with('bot:id,name')
            ->withCount($this->runCounts())
            ->latest('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('Backtests/Sweeps/Index', [
            'sweeps' => BacktestSweepResource::collection($sweeps)->resolve(),
            'active' => $sweeps->contains(fn ($sweep) => (int) $sweep->active_runs_count > 0),
        ]);
    }

    public function create(Request $request): Response
    {
        $bots = $request->user()->bots()
            ->with('strategyConfig')
            ->whereIn('strategy_key', BacktestSweep::GRID_STRATEGIES)
            ->orderBy('name')
            ->get()
            ->filter(fn ($bot) => $bot->strategyConfig !== null)
            ->map(fn ($bot) => [
                'id' => $bot->id,
                'name' => $bot->name,
                'exchange' => $bot->exchange,
                'symbol' => $bot->symbol,
                'strategy_key' => $bot->strategy_key,
                'capital_allocation' => $bot->capital_allocation,
                'fee_rate' => $bot->fee_rate,
                'has_configuration' => true,
            ])
            ->values();

        $days = max(1, (int) config('backtest.default_days', 30));

        return Inertia::render('Backtests/Sweeps/Create', [
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
            'maxCombinations' => BacktestSweep::maximumRuns(),
            'maxCandles' => (int) config('backtest.max_candles', 200_000),
        ]);
    }

    public function show(Request $request, BacktestSweep $sweep): Response
    {
        abort_unless($sweep->user_id === $request->user()->id, 404);

        $sweep->load('bot:id,name');
        $sweep->loadCount($this->runCounts());

        $runs = $sweep->runs()->latest('id')->get();
        $completedIds = $runs
            ->filter(fn ($run) => $run->status === BacktestStatus::Completed)
            ->take(4)
            ->pluck('id')
            ->values();

        return Inertia::render('Backtests/Sweeps/Show', [
            'sweep' => BacktestSweepResource::make($sweep)->resolve(),
            'runs' => BacktestSweepRunResource::collection($runs)->resolve(),
            'compareUrl' => $completedIds->count() >= 2
                ? route('backtests.compare', ['ids' => $completedIds->all()])
                : null,
            'active' => $runs->contains(fn ($run) => $run->isActive()),
        ]);
    }

    public function store(
        StoreBacktestSweepRequest $request,
        CreateBacktestSweep $createBacktestSweep,
        BacktestPeriodValidator $periods,
    ): RedirectResponse {
        $bot = $request->user()->bots()->find($request->integer('bot_id'));

        if ($bot === null) {
            abort(403);
        }

        $timeframe = Timeframe::fromConfig((string) $request->validated('timeframe'));
        $utc = new DateTimeZone('UTC');
        $startAt = (new DateTimeImmutable((string) $request->validated('start_at')))->setTimezone($utc);
        $endAt = (new DateTimeImmutable((string) $request->validated('end_at')))->setTimezone($utc);

        $periodError = $periods->validate($timeframe, $startAt, $endAt);

        if ($periodError !== null) {
            throw ValidationException::withMessages([
                $periodError['field'] => $periodError['message'],
            ]);
        }

        try {
            $sweep = $createBacktestSweep->handle(
                $request->user(),
                $bot,
                $timeframe,
                $startAt,
                $endAt,
                [
                    'center_prices' => $request->validated('center_prices'),
                    'spacing_values' => $request->validated('spacing_values'),
                    'level_counts' => $request->validated('level_counts'),
                ],
                $request->validated('name'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'parameters' => $exception->getMessage(),
            ]);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'bot_id' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('backtest-sweeps.show', $sweep)
            ->with('status', 'Sweep queued. Each combination is running as a backtest.');
    }

    /** @return array<int|string, string|\Closure> */
    private function runCounts(): array
    {
        return [
            'runs',
            'runs as pending_runs_count' => fn ($query) => $query->where('status', BacktestStatus::Pending->value),
            'runs as running_runs_count' => fn ($query) => $query->where('status', BacktestStatus::Running->value),
            'runs as active_runs_count' => fn ($query) => $query->whereIn('status', [
                BacktestStatus::Pending->value,
                BacktestStatus::Running->value,
            ]),
            'runs as completed_runs_count' => fn ($query) => $query->where('status', BacktestStatus::Completed->value),
            'runs as failed_runs_count' => fn ($query) => $query->where('status', BacktestStatus::Failed->value),
        ];
    }
}
