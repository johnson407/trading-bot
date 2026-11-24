<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\ValueObjects\KillSwitchResult;
use App\Models\Bot;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivateKillSwitch
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly CancelBotOrders $cancelBotOrders,
        private readonly CommandPublisher $commands,
        private readonly EngineHeartbeat $heartbeat,
    ) {}

    /**
     * Record the pause intent, then ask the engine to pause every bot. Only a
     * missing engine or a failed publish falls back to local cancellation.
     */
    public function handle(User $user): KillSwitchResult
    {
        $bots = $user->bots()->get();

        DB::transaction(function () use ($bots) {
            foreach ($bots as $bot) {
                if ($bot->desired_status !== BotStatus::Stopped) {
                    $bot->forceFill([
                        'desired_status' => BotStatus::Paused,
                        'kill_switch_requested_at' => now(),
                    ])->save();
                }
            }
        });

        $this->activity->recordUser(
            'kill_switch.activated',
            'Kill-switch activated; pause commands are being sent to the engine.',
            null,
            $user,
            context: ['bot_ids' => $bots->pluck('id')->all()],
            severity: 'critical',
        );

        $published = false;

        try {
            $this->commands->publish('kill_switch', $user->id, null);
            $published = true;
        } catch (Throwable $exception) {
            Log::warning('kill_switch command publish failed', [
                'user_id' => $user->id,
                'error_type' => $exception::class,
            ]);
        }

        // Only a missing engine or a failed publish falls back to local live
        // cancellation. With a fresh heartbeat the engine owns the pause.
        $liveBots = $bots->filter(fn (Bot $bot) => $bot->isLive());
        $emergency = $liveBots->isNotEmpty() && (! $published || ! $this->heartbeat->available());
        $results = [];

        if ($emergency) {
            $tasks = $liveBots->mapWithKeys(fn (Bot $bot) => [
                $bot->id => function () use ($bot) {
                    if (config('concurrency.default') === 'process') {
                        DB::purge();
                    }

                    try {
                        return $this->cancelBotOrders->handle(
                            $bot->id,
                            'risk.kill_switch_cancel_failed',
                            'Kill-switch could not cancel an order.',
                        );
                    } catch (Throwable $exception) {
                        return [
                            'bot_id' => $bot->id,
                            'canceled' => 0,
                            'failed' => 1,
                            'failures' => [['order_id' => 0, 'message' => $exception->getMessage()]],
                        ];
                    }
                },
            ])->all();

            $results = array_values(Concurrency::run($tasks));
        }

        return new KillSwitchResult(
            $bots->pluck('id')->all(),
            $results,
            $published,
            $emergency,
        );
    }
}
