<?php

namespace App\Console\Commands;

use App\Jobs\RunBotTick;
use App\Models\Bot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchBotTicks extends Command
{
    protected $signature = 'bots:dispatch';

    protected $description = 'Dispatch due bot ticks without overlapping work';

    public function handle(): int
    {
        $now = now('UTC');

        Bot::query()
            ->running()
            ->orderBy('id')
            ->each(function (Bot $bot) use ($now) {
                $interval = max(1, (int) $bot->tick_interval_seconds);
                $dueAt = $now->copy()->subSeconds($interval);

                if ($bot->last_tick_at !== null && $bot->last_tick_at->greaterThan($dueAt)) {
                    return;
                }

                // Queue latency can leave last_tick_at stale while a tick is
                // still waiting; this guard keeps one tick per interval.
                if (! Cache::add('bot-dispatch:'.$bot->id, 1, $interval)) {
                    return;
                }

                RunBotTick::dispatch($bot->id);
            });

        return self::SUCCESS;
    }
}
