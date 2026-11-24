<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Engine\CommandPublisher;
use Throwable;

class PauseBot
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly CommandPublisher $commands,
    ) {}

    public function handle(Bot $bot): Bot
    {
        if ($bot->desired_status === BotStatus::Paused && $bot->status === BotStatus::Paused) {
            return $bot;
        }

        $previous = $bot->desired_status;

        $bot->update(['desired_status' => BotStatus::Paused]);

        try {
            $this->commands->publish('bot.pause', $bot->user_id, $bot->id);
        } catch (Throwable $exception) {
            $bot->update(['desired_status' => $previous]);

            throw $exception;
        }

        $this->activity->recordUser(
            'bot.paused',
            'Bot paused',
            $bot,
            context: ['mode' => $bot->mode->value],
        );

        return $bot->refresh();
    }
}
