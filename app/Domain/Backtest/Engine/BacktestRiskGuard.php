<?php

namespace App\Domain\Backtest\Engine;

use App\Domain\Backtest\ValueObjects\BacktestRiskLimits;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\ValueObjects\RiskDecision;
use App\Domain\Support\Decimal;

final class BacktestRiskGuard
{
    public function __construct(
        private readonly BacktestRiskLimits $limits,
        private readonly Decimal $feeRate,
    ) {}

    public function checkOrder(
        OrderSide $side,
        string|Decimal $price,
        string|Decimal $quantity,
        InMemoryPortfolio $portfolio,
        SimulatedOrderBook $book,
    ): RiskDecision {
        $price = Decimal::of($price);
        $quantity = Decimal::of($quantity);

        if (! $price->isPositive() || ! $quantity->isPositive()) {
            return RiskDecision::block(
                'risk.invalid_order',
                'Order price and quantity must be positive.',
                ['price' => $price->toString(), 'quantity' => $quantity->toString()],
                RiskSeverity::Critical,
            );
        }

        $notional = $price->mul($quantity);
        $fee = $notional->mul($this->feeRate);

        if ($side === OrderSide::Buy) {
            $committed = $book->openBuyNotional();
            $committedWithFees = $committed->add($committed->mul($this->feeRate));
            $available = $portfolio->quote->sub($committedWithFees);

            if ($notional->add($fee)->greaterThan($available)) {
                return RiskDecision::block(
                    'risk.insufficient_capital',
                    'Buy notional plus fee exceeds the free quote inventory for this backtest.',
                    [
                        'available_quote' => $available->toString(),
                        'required' => $notional->add($fee)->toString(),
                    ],
                );
            }
        }

        if ($side === OrderSide::Sell) {
            $reserved = $book->reservedSellQuantity();
            $available = $portfolio->base->sub($reserved);

            if ($quantity->greaterThan($available)) {
                return RiskDecision::block(
                    'risk.insufficient_inventory',
                    'Sell quantity exceeds the uncommitted base inventory held by the backtest.',
                    [
                        'base_inventory' => $portfolio->base->toString(),
                        'reserved_sell_quantity' => $reserved->toString(),
                        'available' => $available->toString(),
                        'quantity' => $quantity->toString(),
                    ],
                );
            }
        }

        $positionNotional = $portfolio->base->abs()->mul($price)->add($book->openBuyNotional());
        $projected = $side === OrderSide::Buy
            ? $positionNotional->add($notional)
            : $positionNotional->sub($notional)->max(Decimal::zero());

        if ($projected->greaterThan($this->limits->maxPositionNotional)) {
            return RiskDecision::block(
                'risk.position_limit',
                'Order would exceed the maximum position notional.',
                [
                    'projected_notional' => $projected->toString(),
                    'max_position_notional' => $this->limits->maxPositionNotional->toString(),
                    'side' => $side->value,
                    'order_notional' => $notional->toString(),
                ],
                RiskSeverity::Critical,
            );
        }

        if ($book->openCount() + 1 > $this->limits->maxOpenOrders) {
            return RiskDecision::block(
                'risk.open_order_limit',
                'Order would exceed the maximum number of open orders.',
                [
                    'open_orders' => $book->openCount(),
                    'max_open_orders' => $this->limits->maxOpenOrders,
                ],
            );
        }

        return RiskDecision::allow();
    }

    public function dailyLoss(
        Decimal $realized,
        Decimal $unrealized,
        Decimal $dayStartRealized,
    ): Decimal {
        $pnl = $realized->sub($dayStartRealized)->add($unrealized);

        return $pnl->isNegative() ? $pnl->negate() : Decimal::zero();
    }

    public function dailyLossLimit(): Decimal
    {
        return $this->limits->dailyLossLimit;
    }
}
