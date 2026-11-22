<?php

namespace App\Console\Commands;

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Models\BacktestRun;
use Illuminate\Console\Command;

class FailStaleBacktests extends Command
{
    protected $signature = 'backtests:fail-stale';

    protected $description = 'Fail backtest runs whose worker disappeared or timed out';

    public function handle(): int
    {
        $timeout = max(1, (int) config('backtest.job_timeout', 600));
        $cutoff = now('UTC')->subSeconds($timeout + 60);
        $message = 'The backtest worker stopped before the run finished.';

        $failed = BacktestRun::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($running) use ($cutoff): void {
                    $running->where('status', BacktestStatus::Running->value)
                        // Progress updates touch updated_at, so this is a heartbeat.
                        ->where('updated_at', '<', $cutoff);
                })->orWhere(function ($pending) use ($cutoff): void {
                    $pending->where('status', BacktestStatus::Pending->value)
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->update([
                'status' => BacktestStatus::Failed->value,
                'error' => $message,
                'finished_at' => now('UTC'),
            ]);

        if ($failed > 0) {
            $this->warn("Marked {$failed} stale backtest run(s) as failed.");
        }

        return self::SUCCESS;
    }
}
