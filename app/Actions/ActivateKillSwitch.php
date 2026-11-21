<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\ValueObjects\KillSwitchResult;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Throwable;

class ActivateKillSwitch
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly CancelBotOrders $cancelBotOrders,
    ) {}

    /**
     * Pause every bot immediately, then cancel their open orders in parallel.
     */
    public function handle(User $user): KillSwitchResult
    {
        $bots = $user->bots()->get();

        DB::transaction(function () use ($bots) {
            foreach ($bots as $bot) {
                if ($bot->status !== BotStatus::Stopped) {
                    $bot->forceFill([
                        'status' => BotStatus::Paused,
                        'last_error' => 'Kill-switch activated.',
                    ])->save();
                }
            }
        });

        $this->activity->record(
            'kill_switch.activated',
            'Kill-switch activated; all bots paused and open orders are being canceled.',
            user: $user,
            context: ['bot_ids' => $bots->pluck('id')->all()],
            severity: 'critical',
        );

        $tasks = $bots->mapWithKeys(fn (Bot $bot) => [
            $bot->id => function () use ($bot) {
                if (config('concurrency.default') === 'process') {
                    DB::purge();
                }

                try {
                    return $this->cancelBotOrders->handle($bot->id);
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

        $results = $tasks === [] ? [] : Concurrency::run($tasks);

        return new KillSwitchResult(
            $bots->pluck('id')->all(),
            array_values($results),
        );
    }
}
