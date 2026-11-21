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
        $base = Decimal::of($bot->base_inventory);
        $openOrders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->get();

        $openBuyNotional = Decimal::zero();
        $reservedSellQuantity = Decimal::zero();

        foreach ($openOrders as $openOrder) {
            $remaining = Decimal::of($openOrder->quantity)->sub($openOrder->filled_quantity);

            if (! $remaining->isPositive()) {
                continue;
            }

            if ($openOrder->side === OrderSide::Buy) {
                $openBuyNotional = $openBuyNotional->add($remaining->mul($openOrder->price));
            } else {
                $reservedSellQuantity = $reservedSellQuantity->add($remaining);
            }
        }

        if ($side === OrderSide::Sell) {
            $available = $base->sub($reservedSellQuantity);

            if ($quantity->greaterThan($available)) {
                // Waiting for a buy to fund the sell side is an expected state,
                // not a breach, so it is reported without a per-tick risk event.
                return RiskDecision::block(
                    'risk.insufficient_inventory',
                    'Sell quantity exceeds the uncommitted base inventory held by the bot.',
                    [
                        'base_inventory' => $base->toString(),
                        'reserved_sell_quantity' => $reservedSellQuantity->toString(),
                        'available' => $available->toString(),
                        'quantity' => $quantity->toString(),
                    ],
                    RiskSeverity::Warning,
                );
            }
        }

        $positionNotional = $base->abs()->mul($price)->add($openBuyNotional);
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

        $openOrderCount = $openOrders->count();

        if ($openOrderCount + 1 > $limits['max_open_orders']) {
            return $this->reject($bot, RiskDecision::block(
                'risk.open_order_limit',
                'Order would exceed the maximum number of open orders.',
                [
                    'open_orders' => $openOrderCount,
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
