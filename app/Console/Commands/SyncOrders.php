<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrders as SyncOrdersJob;
use App\Models\Bot;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    protected $signature = 'bots:sync-orders {--bot= : Sync a single live bot id}';

    protected $description = 'Synchronize live orders and fills from the exchange';

    public function handle(): int
    {
        $query = Bot::query()->running()->where('mode', 'live')->orderBy('id');

        if ($this->option('bot')) {
            $query->whereKey($this->option('bot'));
        }

        $count = 0;

        $query->each(function (Bot $bot) use (&$count) {
            SyncOrdersJob::dispatch($bot->id);
            $count++;
        });

        $this->info("{$count} live bot order sync(s) queued.");

        return self::SUCCESS;
    }
}
