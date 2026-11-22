<?php

namespace App\Domain\Exit;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\CancelBotOrders;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\Bot;
use Illuminate\Support\Facades\DB;

class ExitGuardMonitor
{
    public function __construct(
        private readonly CancelBotOrders $cancelOrders,
        private readonly RiskEventRecorder $events,
        private readonly ActivityRecorder $activities,
    ) {}

    /**
     * Evaluate a bot's protective exit rules and pause it on the first match.
     */
    public function evaluate(Bot $bot, string $markPrice): ?ExitSignal
    {
        $bot->refresh();

        if ($bot->status !== BotStatus::Running) {
            return null;
        }

        $rules = new ExitRules(
            stopLossPercent: $bot->stop_loss_percent === null ? null : (string) $bot->stop_loss_percent,
            takeProfitPercent: $bot->take_profit_percent === null ? null : (string) $bot->take_profit_percent,
            rangeLow: $bot->range_low === null ? null : (string) $bot->range_low,
            rangeHigh: $bot->range_high === null ? null : (string) $bot->range_high,
        );

        if ($rules->isEmpty()) {
            return null;
        }

        $signal = $rules->evaluate(
            $markPrice,
            (string) $bot->average_entry_price,
            (string) $bot->base_inventory,
        );

        if ($signal === null) {
            return null;
        }

        $message = sprintf(
            '%s triggered at %s (threshold %s). Bot paused and open orders canceled.',
            $signal->rule->label(),
            $signal->markPrice,
            $signal->threshold,
        );

        $paused = DB::transaction(function () use ($bot, $message): bool {
            $locked = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BotStatus::Running) {
                return false;
            }

            $locked->forceFill([
                'status' => BotStatus::Paused,
                'last_error' => $message,
            ])->save();

            return true;
        });

        if (! $paused) {
            return null;
        }

        $bot->refresh();

        $this->cancelOrders->handle(
            $bot,
            'risk.exit_cancel_failed',
            'Protective exit could not cancel an order.',
            RiskSeverity::Critical,
        );

        $this->events->record(
            $signal->rule->riskEventType(),
            $message,
            $bot,
            context: $signal->context(),
            severity: $this->severity($signal->rule),
        );

        $this->activities->record(
            'bot.exit_guard_triggered',
            $message,
            $bot,
            context: $signal->context(),
            severity: $signal->rule === ExitRule::StopLoss ? 'error' : 'warning',
        );

        return $signal;
    }

    private function severity(ExitRule $rule): RiskSeverity
    {
        return $rule === ExitRule::StopLoss ? RiskSeverity::Critical : RiskSeverity::Warning;
    }
}
