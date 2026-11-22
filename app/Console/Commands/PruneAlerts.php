<?php

namespace App\Console\Commands;

use App\Models\Alert;
use Illuminate\Console\Command;

class PruneAlerts extends Command
{
    protected $signature = 'alerts:prune {--days= : Days to retain}';

    protected $description = 'Delete alerts older than the retention window';

    public function handle(): int
    {
        $option = $this->option('days');

        if ($option === null || $option === '') {
            $days = (int) config('alerts.retention_days', 90);
        } else {
            $days = filter_var($option, FILTER_VALIDATE_INT);

            if ($days === false || $days < 1) {
                $this->error('The --days option must be a positive integer.');

                return self::FAILURE;
            }
        }

        $days = max(1, $days);

        $deleted = Alert::query()
            ->where('created_at', '<', now('UTC')->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} alert(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
