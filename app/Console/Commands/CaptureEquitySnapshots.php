<?php

namespace App\Console\Commands;

use App\Domain\Analytics\EquitySnapshotter;
use Illuminate\Console\Command;

class CaptureEquitySnapshots extends Command
{
    protected $signature = 'analytics:capture-equity';

    protected $description = 'Capture account-level equity snapshots for the current interval';

    public function handle(EquitySnapshotter $snapshotter): int
    {
        $captured = $snapshotter->capture();

        $this->info("Captured equity for {$captured} account(s).");

        return self::SUCCESS;
    }
}
