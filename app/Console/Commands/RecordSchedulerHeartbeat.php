<?php

namespace App\Console\Commands;

use App\Services\Engine\SchedulerHeartbeat;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Record that the Laravel scheduler is alive.';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->record();

        return self::SUCCESS;
    }
}
