<?php

namespace App\Jobs;

use App\Domain\Backtest\Engine\BacktestEngine;
use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\ValueObjects\BacktestConfiguration;
use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Models\BacktestRun;
use App\Services\Backtest\CandleStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunBacktest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->timeout = max(1, (int) config('backtest.job_timeout', 600));
        $this->onQueue('backtests');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('backtest-run-'.$this->runId))->expireAfter($this->timeout),
        ];
    }

    public function handle(CandleStore $store, BacktestEngine $engine): void
    {
        $run = BacktestRun::query()->find($this->runId);

        if (! $run instanceof BacktestRun) {
            return;
        }

        $claimed = BacktestRun::query()
            ->whereKey($run->id)
            ->where('status', BacktestStatus::Pending->value)
            ->update([
                'status' => BacktestStatus::Running->value,
                'started_at' => now('UTC'),
                'finished_at' => null,
                'error' => null,
                'candles_processed' => 0,
                'progress' => 0,
            ]);

        if ($claimed === 0) {
            return;
        }

        $run->refresh();

        try {
            $configuration = BacktestConfiguration::fromSnapshot($run->config_snapshot);
            $candles = $store->ensure(
                $configuration->exchange,
                $configuration->symbol,
                $configuration->timeframe,
                $configuration->startAt,
                $configuration->endAt,
            );

            if (count($candles) === 0) {
                throw new RuntimeException('No historical data available.');
            }

            $total = count($candles);
            $result = $engine->run(
                $configuration,
                $candles,
                function (int $processed) use ($run, $total): void {
                    BacktestRun::query()
                        ->whereKey($run->id)
                        ->where('status', BacktestStatus::Running->value)
                        ->update([
                            'candles_processed' => $processed,
                            'progress' => min(99, (int) floor($processed / max(1, $total) * 100)),
                        ]);
                },
            );

            DB::transaction(function () use ($run, $result): void {
                $locked = BacktestRun::query()->whereKey($run->id)->lockForUpdate()->first();

                if (! $locked instanceof BacktestRun || $locked->status !== BacktestStatus::Running) {
                    // A stale sweep or another failure won the race. Do not
                    // attach fills to a run that is no longer running.
                    return;
                }

                $run->fills()->delete();

                $now = now('UTC');

                foreach (array_chunk($result->fills, 500) as $fills) {
                    $rows = array_map(
                        fn (SimulatedFill $fill) => [
                            'backtest_run_id' => $run->id,
                            'sequence' => $fill->sequence,
                            'side' => $fill->side->value,
                            'price' => $fill->price->toString(),
                            'quantity' => $fill->quantity->toString(),
                            'fee' => $fill->fee->toString(),
                            'fee_currency' => $fill->feeCurrency,
                            'filled_at' => $fill->filledAt->format('Y-m-d H:i:s'),
                            'grid_level_sequence' => $fill->gridLevelSequence,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $fills,
                    );

                    $run->fills()->insert($rows);
                }

                BacktestRun::query()
                    ->whereKey($run->id)
                    ->where('status', BacktestStatus::Running->value)
                    ->update([
                        'status' => BacktestStatus::Completed->value,
                        'end_reason' => $result->metrics->endReason,
                        'candles_processed' => $result->metrics->candlesProcessed,
                        'progress' => 100,
                        'metrics' => $result->metrics->toArray(),
                        'equity_curve' => array_map(
                            fn ($point) => $point->toArray(),
                            $result->equityCurve,
                        ),
                        'error' => null,
                        'finished_at' => $now,
                    ]);
            });
        } catch (Throwable $exception) {
            Log::error('backtest.failed', [
                'backtest_run_id' => $run->id,
                'exception' => $exception,
            ]);

            BacktestRun::query()
                ->whereKey($run->id)
                ->where('status', BacktestStatus::Running->value)
                ->update([
                    'status' => BacktestStatus::Failed->value,
                    'error' => $this->safeMessage($exception),
                    'finished_at' => now('UTC'),
                ]);
        }
    }

    /**
     * Covers worker timeouts and jobs failed outside the simulation try/catch.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $exception === null
            ? 'The backtest stopped before it could finish.'
            : $this->safeMessage($exception);

        $staleBefore = now('UTC')->subSeconds(60);

        BacktestRun::query()
            ->whereKey($this->runId)
            ->where(function ($query) use ($staleBefore): void {
                $query->where('status', BacktestStatus::Pending->value)
                    ->orWhere('updated_at', '<', $staleBefore);
            })
            ->whereIn('status', [
                BacktestStatus::Pending->value,
                BacktestStatus::Running->value,
            ])
            ->update([
                'status' => BacktestStatus::Failed->value,
                'error' => $message,
                'finished_at' => now('UTC'),
            ]);

        Log::error('backtest.failed_outside_simulation', [
            'backtest_run_id' => $this->runId,
            'exception' => $exception,
        ]);
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = 'The backtest failed.';
        }

        return mb_strimwidth($message, 0, 500, '...');
    }
}
