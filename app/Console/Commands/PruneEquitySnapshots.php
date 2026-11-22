<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use Illuminate\Console\Command;

class PruneEquitySnapshots extends Command
{
    protected $signature = 'analytics:prune-equity {--days= : Days to retain}';

    protected $description = 'Delete equity snapshots older than the retention window';

    public function handle(): int
    {
        $option = $this->option('days');

        if ($option === null || $option === '') {
            $days = (int) config('analytics.retention_days', 90);
        } else {
            $days = filter_var($option, FILTER_VALIDATE_INT);

            if ($days === false || $days < 1) {
                $this->error('The --days option must be a positive integer.');

                return self::FAILURE;
            }
        }

        $days = max(1, $days);

        $deleted = EquitySnapshot::query()
            ->where('captured_at', '<', now('UTC')->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} equity snapshot(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
