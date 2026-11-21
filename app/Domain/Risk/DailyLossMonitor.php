<?php

namespace App\Domain\Risk;

use App\Actions\CancelOrder;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Risk\Enums\RiskScope;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyLossMonitor
{
    public function __construct(
        private readonly CancelOrder $cancelOrder,
        private readonly ActivityRecorder $activity,
        private readonly RiskEventRecorder $events,
        private readonly InventoryLedger $ledger,
        private readonly RiskLimitService $limits,
    ) {}

    /**
     * Evaluate the bot's per-bot loss limit and the account-wide loss limit.
     * A breached limit pauses the affected bot(s) and cancels their open orders.
     */
    public function evaluate(Bot $bot, Decimal|string|null $markPrice = null): void
    {
        $bot = $bot->fresh();
        $mark = $markPrice === null ? null : Decimal::of($markPrice);
        $botLimit = $this->botLimit($bot);
        $loss = $this->dailyLoss($bot, $botLimit, $mark);

        if ($loss->greaterThanOrEqual(Decimal::of($botLimit->daily_loss_limit))) {
            $this->pause($bot, $loss, Decimal::of($botLimit->daily_loss_limit), 'bot');
        }

        $userLimit = $this->userLimit($bot);
        $total = $this->userDailyLoss($bot, $userLimit, $mark);
        $bots = Bot::query()->where('user_id', $bot->user_id)->get();

        if ($total->greaterThanOrEqual(Decimal::of($userLimit->daily_loss_limit))) {
            foreach ($bots as $userBot) {
                $this->pause($userBot, $total, Decimal::of($userLimit->daily_loss_limit), 'user');
            }
        }
    }

    /**
     * Account-wide daily loss uses one summed baseline for the UTC day and each
     * bot's own most recent mark instead of applying one symbol's price to all.
     */
    public function userDailyLoss(Bot $bot, RiskLimit $limit, Decimal|string|null $markPrice = null): Decimal
    {
        $mark = $markPrice === null ? null : Decimal::of($markPrice);
        $baseline = $this->resetUserIfNeeded($limit, $bot);
        $bots = Bot::query()->where('user_id', $bot->user_id)->get();
        $realized = Decimal::zero();
        $unrealized = Decimal::zero();

        foreach ($bots as $userBot) {
            $realized = $realized->add($userBot->realized_pnl);
            $botMark = $this->markFor($userBot, $bot->id, $mark);

            if ($botMark !== null) {
                $unrealized = $unrealized->add($this->ledger->unrealizedPnl($userBot, $botMark));
            }
        }

        $pnl = $realized->sub($baseline)->add($unrealized);

        return $pnl->isNegative() ? $pnl->negate() : Decimal::zero();
    }

    public function dailyLoss(Bot $bot, ?RiskLimit $limit = null, Decimal|string|null $markPrice = null): Decimal
    {
        $limit ??= $this->botLimit($bot);
        $baseline = $this->resetIfNeeded($limit, $bot);
        $realized = Decimal::of($bot->realized_pnl)->sub($baseline);
        $unrealized = $markPrice === null
            ? Decimal::zero()
            : $this->ledger->unrealizedPnl($bot, Decimal::of($markPrice));
        $pnl = $realized->add($unrealized);

        return $pnl->isNegative() ? $pnl->negate() : Decimal::zero();
    }

    public function botLimit(Bot $bot): RiskLimit
    {
        return $this->limits->forBot($bot);
    }

    public function userLimit(Bot $bot): RiskLimit
    {
        return $this->limits->forUser($bot->user);
    }

    private function resetIfNeeded(RiskLimit $limit, Bot $bot): Decimal
    {
        $today = Carbon::now('UTC')->startOfDay();

        if ($limit->day_start !== null && $limit->day_start->greaterThanOrEqualTo($today)) {
            return Decimal::of($limit->day_start_realized_pnl ?? '0');
        }

        return DB::transaction(function () use ($limit, $bot, $today) {
            $locked = RiskLimit::query()->lockForUpdate()->find($limit->id);
            $locked->forceFill([
                'day_start' => $today,
                'day_start_realized_pnl' => $bot->realized_pnl,
            ])->save();

            return Decimal::of($bot->realized_pnl);
        });
    }

    private function resetUserIfNeeded(RiskLimit $limit, Bot $bot): Decimal
    {
        $today = Carbon::now('UTC')->startOfDay();

        if ($limit->day_start !== null && $limit->day_start->greaterThanOrEqualTo($today)) {
            return Decimal::of($limit->day_start_realized_pnl ?? '0');
        }

        return DB::transaction(function () use ($limit, $bot, $today) {
            $locked = RiskLimit::query()->lockForUpdate()->find($limit->id);

            if ($locked->day_start !== null && $locked->day_start->greaterThanOrEqualTo($today)) {
                return Decimal::of($locked->day_start_realized_pnl ?? '0');
            }

            $sum = Decimal::zero();

            foreach (Bot::query()->where('user_id', $bot->user_id)->get() as $userBot) {
                $sum = $sum->add($userBot->realized_pnl);
            }

            $locked->forceFill([
                'day_start' => $today,
                'day_start_realized_pnl' => $sum->toString(),
            ])->save();

            return $sum;
        });
    }

    private function markFor(Bot $bot, int $currentBotId, ?Decimal $currentMark): ?Decimal
    {
        if ($bot->id === $currentBotId && $currentMark !== null) {
            return $currentMark;
        }

        $stored = $bot->settings['mark_price'] ?? null;

        if (is_numeric($stored) && Decimal::of((string) $stored)->isPositive()) {
            return Decimal::of((string) $stored);
        }

        if (Decimal::of($bot->average_entry_price)->isPositive()) {
            return Decimal::of($bot->average_entry_price);
        }

        return null;
    }

    private function pause(Bot $bot, Decimal $loss, Decimal $limit, string $scope): void
    {
        $bot = $bot->fresh();

        if ($bot->status === BotStatus::Paused || $bot->status === BotStatus::Stopped) {
            return;
        }

        $bot->forceFill([
            'status' => BotStatus::Paused,
            'last_error' => "Daily loss limit breached ({$scope}).",
        ])->save();

        foreach ($bot->orders()->whereIn('status', ['pending', 'open', 'partially_filled'])->get() as $order) {
            try {
                $this->cancelOrder->handle($order);
            } catch (\Throwable $exception) {
                $this->events->record(
                    'risk.daily_loss_cancel_failed',
                    'An order could not be canceled after the daily loss limit was breached.',
                    $bot,
                    context: [
                        'order_id' => $order->id,
                        'client_order_id' => $order->client_order_id,
                        'error' => $exception->getMessage(),
                    ],
                    severity: RiskSeverity::Critical,
                );
            }
        }

        $this->events->record(
            'risk.daily_loss_limit',
            'Daily loss limit breached; bot paused.',
            $bot,
            context: [
                'scope' => $scope,
                'loss' => $loss->toString(),
                'limit' => $limit->toString(),
            ],
            severity: RiskSeverity::Critical,
        );

        $this->activity->record(
            'bot.daily_loss_paused',
            'Bot paused after breaching the daily loss limit.',
            $bot,
            context: ['scope' => $scope, 'loss' => $loss->toString(), 'limit' => $limit->toString()],
            severity: 'error',
        );
    }
}
