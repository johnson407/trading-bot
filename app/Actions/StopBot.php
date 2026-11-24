<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Support\Facades\Log;
use Throwable;

class StopBot
{
    public function __construct(
        private readonly CancelBotOrders $cancelBotOrders,
        private readonly ActivityRecorder $activity,
        private readonly CommandPublisher $commands,
        private readonly EngineHeartbeat $heartbeat,
    ) {}

    public function handle(Bot $bot): Bot
    {
        if ($bot->desired_status === BotStatus::Stopped && $bot->status === BotStatus::Stopped) {
            return $bot;
        }

        $bot->update(['desired_status' => BotStatus::Stopped]);

        $published = false;

        try {
            $this->commands->publish('bot.stop', $bot->user_id, $bot->id);
            $published = true;
        } catch (Throwable $exception) {
            Log::warning('bot.stop command publish failed', [
                'bot_id' => $bot->id,
                'error_type' => $exception::class,
            ]);
        }

        // A fresh heartbeat plus a successful publish means the engine owns the
        // stop. Otherwise Laravel cancels live orders as the emergency path.
        $emergency = $bot->isLive() && (! $published || ! $this->heartbeat->available());
        $result = $emergency
            ? $this->cancelBotOrders->handle(
                $bot->id,
                'risk.stop_cancel_failed',
                'Stop could not cancel an order.',
            )
            : null;

        $canceled = $result['canceled'] ?? 0;
        $failed = $result['failed'] ?? 0;

        $this->activity->recordUser(
            'bot.stopped',
            'Bot stopped',
            $bot,
            context: [
                'command_published' => $published,
                'emergency_cancel' => $emergency,
                'canceled_orders' => $canceled,
                'failed_orders' => $failed,
            ],
            severity: $failed === 0 ? 'info' : 'error',
        );

        return $bot->refresh();
    }
}
