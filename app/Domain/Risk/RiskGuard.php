<?php

namespace App\Domain\Risk;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\Enums\RiskScope;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\ValueObjects\RiskDecision;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\RiskLimit;

class RiskGuard
{
    public function __construct(private readonly RiskEventRecorder $events) {}

    /**
     * Check whether an order would stay inside the bot's exposure and open-order limits.
     */
    public function checkOrder(Bot $bot, OrderSide $side, Decimal|string $price, Decimal|string $quantity): RiskDecision
    {
        $price = Decimal::of($price);
        $quantity = Decimal::of($quantity);

        if (! $price->isPositive() || ! $quantity->isPositive()) {
            return $this->reject($bot, RiskDecision::block(
                'risk.invalid_order',
                'Order price and quantity must be positive.',
                ['price' => $price->toString(), 'quantity' => $quantity->toString()],
                RiskSeverity::Critical,
            ));
        }

        $limits = $this->limits($bot);
        $notional = $price->mul($quantity);
        $positionNotional = Decimal::of($bot->base_inventory)->abs()->mul($price);
        $projected = $side === OrderSide::Buy
            ? $positionNotional->add($notional)
            : $positionNotional->sub($notional)->max(Decimal::zero());

        if ($projected->greaterThan($limits['max_position_notional'])) {
            return $this->reject($bot, RiskDecision::block(
                'risk.position_limit',
                'Order would exceed the maximum position notional.',
                [
                    'projected_notional' => $projected->toString(),
                    'max_position_notional' => $limits['max_position_notional']->toString(),
                    'side' => $side->value,
                    'order_notional' => $notional->toString(),
                ],
                RiskSeverity::Critical,
            ));
        }

        $openOrders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->count();

        if ($openOrders + 1 > $limits['max_open_orders']) {
            return $this->reject($bot, RiskDecision::block(
                'risk.open_order_limit',
                'Order would exceed the maximum number of open orders.',
                [
                    'open_orders' => $openOrders,
                    'max_open_orders' => $limits['max_open_orders'],
                ],
                RiskSeverity::Warning,
            ));
        }

        return RiskDecision::allow();
    }

    /**
     * Resolve the effective limits: a bot-specific limit overrides the user-wide limit.
     *
     * @return array{max_position_notional: Decimal, max_open_orders: int, daily_loss_limit: Decimal, day_start: ?\Illuminate\Support\Carbon}
     */
    public function limits(Bot $bot): array
    {
        $limit = RiskLimit::query()
            ->where('bot_id', $bot->id)
            ->where('scope', RiskScope::Bot->value)
            ->first()
            ?? RiskLimit::query()
                ->where('user_id', $bot->user_id)
                ->where('scope', RiskScope::User->value)
                ->whereNull('bot_id')
                ->first();

        return [
            'max_position_notional' => Decimal::of($limit->max_position_notional ?? config('risk.defaults.max_position_notional', '1000')),
            'max_open_orders' => (int) ($limit->max_open_orders ?? config('risk.defaults.max_open_orders', 20)),
            'daily_loss_limit' => Decimal::of($limit->daily_loss_limit ?? config('risk.defaults.daily_loss_limit', '100')),
            'day_start' => $limit?->day_start,
        ];
    }

    private function reject(Bot $bot, RiskDecision $decision): RiskDecision
    {
        $this->events->record(
            $decision->type,
            $decision->message,
            $bot,
            context: $decision->context,
            severity: $decision->severity,
        );

        return $decision;
    }
}
