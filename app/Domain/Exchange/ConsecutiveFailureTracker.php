<?php

namespace App\Domain\Exchange;

use App\Actions\CancelOrder;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\Bot;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConsecutiveFailureTracker
{
    private readonly int $threshold;

    public function __construct(
        private readonly CancelOrder $cancelOrder,
        private readonly ActivityRecorder $activity,
        private readonly RiskEventRecorder $riskEvents,
        ?int $threshold = null,
    ) {
        $this->threshold = $threshold ?? (int) config('trading.failure.max_consecutive', 3);
    }

    public function recordFailure(Bot $bot, Throwable|string $error, string $operation = 'exchange'): int
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $paused = false;

        $failures = DB::transaction(function () use ($bot, $message, &$paused) {
            $locked = Bot::query()->lockForUpdate()->findOrFail($bot->id);
            $locked->consecutive_failures++;
            $locked->last_error = $message;

            if ($locked->consecutive_failures >= $this->threshold) {
                $paused = true;
                if ($locked->status !== BotStatus::Stopped) {
                    $locked->status = BotStatus::Paused;
                }
            }

            $locked->save();
            $bot->setRawAttributes($locked->getAttributes(), true);

            return $locked->consecutive_failures;
        });

        if (! $paused) {
            return $failures;
        }

        $cancellationFailures = 0;

        foreach ($bot->orders()->whereIn('status', [
            OrderStatus::Pending->value,
            OrderStatus::Open->value,
            OrderStatus::PartiallyFilled->value,
        ])->get() as $order) {
            try {
                $this->cancelOrder->handle($order);
            } catch (Throwable) {
                $cancellationFailures++;
            }
        }

        $this->activity->record(
            'exchange.failure_threshold',
            'Bot paused after consecutive exchange failures.',
            $bot,
            context: [
                'operation' => $operation,
                'consecutive_failures' => $failures,
                'cancellation_failures' => $cancellationFailures,
                'error' => $message,
            ],
            severity: 'error',
        );

        $this->riskEvents->record(
            'exchange.failure_threshold',
            'Consecutive exchange failures reached the pause threshold.',
            $bot,
            context: [
                'operation' => $operation,
                'consecutive_failures' => $failures,
                'cancellation_failures' => $cancellationFailures,
            ],
            severity: RiskSeverity::Critical,
        );

        return $failures;
    }

    public function recordSuccess(Bot $bot): void
    {
        if ($bot->consecutive_failures === 0) {
            return;
        }

        $bot->forceFill(['consecutive_failures' => 0])->save();
    }

    public function threshold(): int
    {
        return $this->threshold;
    }
}
