<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;

class StartBot
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly LiveModeGuard $liveModeGuard,
    ) {}

    public function handle(
        Bot $bot,
        ?LiveStartConfirmation $confirmation = null,
        ?ExchangeClient $exchange = null,
    ): Bot {
        if ($bot->status === BotStatus::Running) {
            return $bot;
        }

        $this->liveModeGuard->assertCanStart($bot, $confirmation, $exchange);

        $bot->update([
            'status' => BotStatus::Running,
            'last_error' => null,
        ]);

        $this->activity->record(
            'bot.started',
            'Bot started',
            $bot,
            context: ['mode' => $bot->mode->value, 'symbol' => $bot->symbol],
        );

        return $bot->refresh();
    }
}
