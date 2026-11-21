<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotReconciler;
use App\Models\Bot;
use Illuminate\Console\Command;

class ReconcileBots extends Command
{
    protected $signature = 'bots:reconcile {--bot= : Reconcile a single bot id}';

    protected $description = 'Resume running bots and reconcile their open orders and balances';

    public function handle(BotReconciler $reconciler): int
    {
        $query = Bot::query()->running()->orderBy('id');

        if ($this->option('bot')) {
            $query->whereKey($this->option('bot'));
        }

        $bots = $query->get();
        $conflicts = 0;

        foreach ($bots as $bot) {
            $result = $reconciler->reconcile($bot);
            $this->line("Bot {$bot->id}: {$result->status} matched={$result->matched} filled={$result->filled} canceled={$result->canceled}");

            if (! $result->ok() && $result->status !== 'skipped') {
                $conflicts++;
            }
        }

        return $conflicts === 0 ? self::SUCCESS : self::FAILURE;
    }
}
