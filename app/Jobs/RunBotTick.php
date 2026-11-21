<?php

namespace App\Jobs;

use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RunBotTick implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 10;

    public function __construct(public readonly int $botId) {}

    public function uniqueId(): string
    {
        return 'bot-tick:'.$this->botId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bot-tick:'.$this->botId))->expireAfter(30)];
    }

    public function handle(BotTickPipeline $pipeline): void
    {
        $bot = Bot::query()->find($this->botId);

        if (! $bot instanceof Bot || ! $bot->isRunning()) {
            return;
        }

        $pipeline->tick($bot);
    }
}
