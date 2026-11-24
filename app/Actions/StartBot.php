<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
use App\Services\Engine\CommandPublisher;
use Throwable;

class StartBot
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly LiveModeGuard $liveModeGuard,
        private readonly CommandPublisher $commands,
    ) {}

    public function handle(
        Bot $bot,
        ?LiveStartConfirmation $confirmation = null,
        ?ExchangeClient $exchange = null,
    ): Bot {
        if ($bot->desired_status === BotStatus::Running && $bot->status === BotStatus::Running) {
            return $bot;
        }

        $this->liveModeGuard->assertCanStart($bot, $confirmation, $exchange);

        $previous = $bot->desired_status;
        $wasDesiredRunning = $previous === BotStatus::Running;

        $bot->update(['desired_status' => BotStatus::Running]);

        try {
            $this->commands->publish('bot.start', $bot->user_id, $bot->id);
        } catch (Throwable $exception) {
            $bot->update(['desired_status' => $previous]);

            throw $exception;
        }

        if (! $wasDesiredRunning) {
            $this->activity->recordUser(
                'bot.started',
                'Bot started',
                $bot,
                context: ['mode' => $bot->mode->value, 'symbol' => $bot->symbol],
            );
        }

        return $bot->refresh();
    }
}
