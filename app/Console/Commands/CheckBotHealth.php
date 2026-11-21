<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotHealthMonitor;
use Illuminate\Console\Command;

class CheckBotHealth extends Command
{
    protected $signature = 'bots:health';

    protected $description = 'Pause running bots whose last successful tick is stale';

    public function handle(BotHealthMonitor $monitor): int
    {
        $stale = $monitor->check();

        foreach ($stale as $bot) {
            $this->warn("Bot {$bot->id}: stale and paused");
        }

        $this->info(count($stale).' stale bot(s) paused.');

        return self::SUCCESS;
    }
}
