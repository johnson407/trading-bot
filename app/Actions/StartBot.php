<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Risk\LiveCredentialPolicy;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Services\Engine\CommandPublisher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

class StartBot
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly LiveModeGuard $liveModeGuard,
        private readonly LiveCredentialPolicy $liveCredentials,
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

        if ($bot->isLive() && $bot->exchange_credential_id !== null) {
            $this->liveCredentials->assertCanStart($bot);
        }

        $this->liveModeGuard->assertCanStart($bot, $confirmation, $exchange);

        $previous = $bot->desired_status;
        $wasDesiredRunning = $previous === BotStatus::Running;

        $updated = DB::transaction(function () use ($bot): int {
            /** @var Bot $locked */
            $locked = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isLive() && $locked->exchange_credential_id !== null) {
                ExchangeCredential::query()
                    ->whereKey($locked->exchange_credential_id)
                    ->lockForUpdate()
                    ->first();
            }

            $this->liveCredentials->assertCanStart($locked);

            return Bot::query()
                ->whereKey($bot->getKey())
                ->update(['desired_status' => BotStatus::Running->value]);
        });

        if ($updated === 0) {
            throw (new ModelNotFoundException)->setModel(Bot::class, [$bot->getKey()]);
        }

        try {
            $this->commands->publish('bot.start', $bot->user_id, $bot->id);
        } catch (Throwable $exception) {
            Bot::query()
                ->whereKey($bot->getKey())
                ->update(['desired_status' => $previous->value]);

            throw $exception;
        }

        if (! $wasDesiredRunning) {
            $this->activity->recordUser(
                'bot.started',
                'Bot started',
                $bot,
                context: ['mode' => $bot->mode->value, 'symbol' => $bot->symbol],
            );
        } elseif ($bot->status === BotStatus::Paused) {
            $this->activity->recordUser(
                'bot.started',
                'Bot resumed',
                $bot,
                context: ['mode' => $bot->mode->value, 'symbol' => $bot->symbol, 'resumed' => true],
            );
        }

        return $bot->refresh();
    }
}
