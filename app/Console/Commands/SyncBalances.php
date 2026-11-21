<?php

namespace App\Console\Commands;

use App\Domain\Bot\BalanceSynchronizer;
use App\Models\Bot;
use Illuminate\Console\Command;

class SyncBalances extends Command
{
    protected $signature = 'bots:sync-balances';

    protected $description = 'Refresh live bot balances from their exchange';

    public function handle(BalanceSynchronizer $synchronizer): int
    {
        $count = 0;
        $skipped = 0;

        Bot::query()->where('mode', 'live')->whereIn('status', ['running', 'idle'])->each(function (Bot $bot) use ($synchronizer, &$count, &$skipped) {
            if ($synchronizer->sync($bot) === null) {
                $skipped++;
            } else {
                $count++;
            }
        });

        $this->info("{$count} live bot balance(s) refreshed, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
