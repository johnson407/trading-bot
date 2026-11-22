<?php

namespace App\Domain\Analytics;

use App\Domain\Portfolio\MarkPrice;
use App\Domain\Support\Decimal;
use App\Models\EquitySnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures one account-level equity snapshot per user and interval bucket.
 *
 * The snapshot is intentionally outside the bot tick path: a slow or failing
 * capture must never delay or roll back trading work. Each user is isolated so
 * one bad account cannot prevent the rest from being captured.
 */
class EquitySnapshotter
{
    public function capture(): int
    {
        $captured = 0;
        $capturedAt = $this->bucketStart();

        User::query()
            ->whereHas('bots')
            ->with('bots')
            ->each(function (User $user) use (&$captured, $capturedAt): void {
                try {
                    $this->snapshotUser($user, $capturedAt);
                    $captured++;
                } catch (Throwable $exception) {
                    Log::error('Failed to capture equity snapshot.', [
                        'user_id' => $user->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $captured;
    }

    protected function snapshotUser(User $user, CarbonImmutable $capturedAt): void
    {
        if ($user->bots->isEmpty()) {
            return;
        }

        $quoteValue = Decimal::zero();
        $baseValue = Decimal::zero();
        $realizedPnl = Decimal::zero();
        $unrealizedPnl = Decimal::zero();
        $feesPaid = Decimal::zero();
        $botCount = 0;

        foreach ($user->bots as $bot) {
            $quote = Decimal::of($bot->quote_inventory);
            $base = Decimal::of($bot->base_inventory);
            $mark = MarkPrice::for($bot);

            $botBaseValue = $mark === null ? Decimal::zero() : $base->mul($mark);

            $quoteValue = $quoteValue->add($quote);
            $baseValue = $baseValue->add($botBaseValue);
            $realizedPnl = $realizedPnl->add(Decimal::of($bot->realized_pnl));
            $feesPaid = $feesPaid->add(Decimal::of($bot->fees_paid));

            if ($base->isPositive() && $mark !== null) {
                $unrealizedPnl = $unrealizedPnl->add(
                    $mark->sub(Decimal::of($bot->average_entry_price))->mul($base)
                );
            }

            $botCount++;
        }

        EquitySnapshot::query()->upsert(
            [[
                'user_id' => $user->id,
                'captured_at' => $capturedAt,
                'equity' => $quoteValue->add($baseValue)->toString(),
                'quote_value' => $quoteValue->toString(),
                'base_value' => $baseValue->toString(),
                'realized_pnl' => $realizedPnl->toString(),
                'unrealized_pnl' => $unrealizedPnl->toString(),
                'fees_paid' => $feesPaid->toString(),
                'bot_count' => $botCount,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'captured_at'],
            [
                'equity',
                'quote_value',
                'base_value',
                'realized_pnl',
                'unrealized_pnl',
                'fees_paid',
                'bot_count',
                'updated_at',
            ],
        );
    }

    protected function bucketStart(): CarbonImmutable
    {
        $intervalMinutes = max(1, (int) config('analytics.capture_interval_minutes', 5));
        $intervalSeconds = $intervalMinutes * 60;
        $bucket = intdiv(now()->getTimestamp(), $intervalSeconds) * $intervalSeconds;

        return CarbonImmutable::createFromTimestampUTC($bucket);
    }
}
