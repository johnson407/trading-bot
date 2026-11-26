<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Engine\CommandPublisher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $updated = Bot::query()
            ->whereKey($bot->getKey())
            ->update(['desired_status' => BotStatus::Paused->value]);

        if ($updated === 0) {
            throw (new ModelNotFoundException)->setModel(Bot::class, [$bot->getKey()]);
        }

        try {
            $this->commands->publish('bot.pause', $bot->user_id, $bot->id);
        } catch (Throwable $exception) {
            Bot::query()
                ->whereKey($bot->getKey())
                ->update(['desired_status' => $previous->value]);

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
