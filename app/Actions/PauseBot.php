<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;

class PauseBot
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function handle(Bot $bot): Bot
    {
        if ($bot->status === BotStatus::Paused) {
            return $bot;
        }

        $bot->update(['status' => BotStatus::Paused]);

        $this->activity->record(
            'bot.paused',
            'Bot paused',
            $bot,
            context: ['mode' => $bot->mode->value],
        );

        return $bot->refresh();
    }
}
