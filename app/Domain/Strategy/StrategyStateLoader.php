<?php

namespace App\Domain\Strategy;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Strategy\Exceptions\InvalidStrategyStateException;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Models\Bot;

class StrategyStateLoader
{
    public function __construct(
        private readonly StrategyStateSerializer $serializer,
        private readonly ActivityRecorder $activity,
    ) {
    }

    public function load(Bot $bot): StrategyState
    {
        $config = $bot->strategyConfig;

        if ($config === null) {
            return $this->pause($bot, 'the strategy configuration is missing');
        }

        try {
            return $this->serializer->deserialize($config->state, $bot->strategy_key);
        } catch (InvalidStrategyStateException $exception) {
            return $this->pause($bot, $exception->getMessage(), $exception);
        }
    }

    private function pause(Bot $bot, string $reason, ?\Throwable $exception = null): never
    {
        $bot->forceFill([
            'status' => BotStatus::Paused,
            'last_error' => $reason,
        ])->save();

        $this->activity->record(
            'bot.strategy_state_invalid',
            'Bot paused because its strategy state could not be loaded',
            $bot,
            context: ['reason' => $reason],
            severity: 'error',
        );

        throw $exception ?? InvalidStrategyStateException::forStrategy($bot->strategy_key, $reason);
    }
}
