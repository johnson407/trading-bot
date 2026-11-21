<?php

namespace App\Domain\Backtest\Engine;

use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Backtest\ValueObjects\SimulatedOrder;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use DateTimeImmutable;

final class SimulatedOrderBook
{
    /** @var array<string, SimulatedOrder> */
    private array $orders = [];

    private int $nextOrderId = 1;

    private int $nextFillSequence = 1;

    public function __construct(private readonly string $quoteCurrency = 'USDT') {}

    public function place(
        OrderSide $side,
        string|Decimal $price,
        string|Decimal $quantity,
        ?int $gridLevelSequence,
        int $candleIndex,
        string $reason = 'strategy.tick',
    ): SimulatedOrder {
        $order = new SimulatedOrder(
            clientOrderId: 'backtest-'.$this->nextOrderId++,
            side: $side,
            price: Decimal::of($price),
            quantity: Decimal::of($quantity),
            gridLevelSequence: $gridLevelSequence,
            placedCandleIndex: $candleIndex,
            filledQuantity: Decimal::zero(),
            status: OrderStatus::Open,
        );

        $this->orders[$order->clientOrderId] = $order;

        return $order;
    }

    /** @return list<SimulatedOrder> */
    public function openOrders(): array
    {
        return array_values(array_filter(
            $this->orders,
            fn (SimulatedOrder $order) => $order->isOpen(),
        ));
    }

    /** @return list<SimulatedOrder> */
    public function openOrdersBefore(int $candleIndex): array
    {
        return array_values(array_filter(
            $this->openOrders(),
            fn (SimulatedOrder $order) => $order->isEligible($candleIndex),
        ));
    }

    /**
     * Orders crossed by the close, nearest to the close first, matching the
     * live paper book's ordering.
     *
     * @return list<SimulatedOrder>
     */
    public function qualifyingOrders(Decimal $close, int $candleIndex): array
    {
        $qualifying = array_values(array_filter(
            $this->openOrdersBefore($candleIndex),
            fn (SimulatedOrder $order) => $order->side === OrderSide::Buy
                ? $close->lessThanOrEqual($order->price)
                : $close->greaterThanOrEqual($order->price),
        ));

        usort($qualifying, function (SimulatedOrder $a, SimulatedOrder $b) use ($close) {
            return $a->price->sub($close)->abs()->compare($b->price->sub($close)->abs())
                ?: $a->price->compare($b->price);
        });

        return $qualifying;
    }

    public function fill(SimulatedOrder $order, DateTimeImmutable $filledAt, Decimal $feeRate): SimulatedFill
    {
        $quantity = $order->remaining();
        $fee = $order->price->mul($quantity)->mul($feeRate);
        $order->filledQuantity = $order->quantity;
        $order->status = OrderStatus::Filled;

        return new SimulatedFill(
            sequence: $this->nextFillSequence++,
            side: $order->side,
            price: $order->price,
            quantity: $quantity,
            fee: $fee,
            feeCurrency: $this->quoteCurrency,
            filledAt: $filledAt,
            clientOrderId: $order->clientOrderId,
            gridLevelSequence: $order->gridLevelSequence,
        );
    }

    public function cancel(string $clientOrderId): ?SimulatedOrder
    {
        $order = $this->orders[$clientOrderId] ?? null;

        if (! $order instanceof SimulatedOrder || ! $order->isOpen()) {
            return null;
        }

        $order->status = OrderStatus::Canceled;

        return $order;
    }

    /** @return list<SimulatedOrder> */
    public function cancelAll(): array
    {
        $canceled = [];

        foreach ($this->openOrders() as $order) {
            $order->status = OrderStatus::Canceled;
            $canceled[] = $order;
        }

        return $canceled;
    }

    public function hasOpenAtPrice(string|Decimal $price): bool
    {
        $target = Decimal::of($price);

        foreach ($this->openOrders() as $order) {
            if ($order->price->equals($target)) {
                return true;
            }
        }

        return false;
    }

    public function openForSequence(int $sequence): ?SimulatedOrder
    {
        foreach ($this->openOrders() as $order) {
            if ($order->gridLevelSequence === $sequence) {
                return $order;
            }
        }

        return null;
    }

    public function openCount(): int
    {
        return count($this->openOrders());
    }

    public function openBuyNotional(): Decimal
    {
        $total = Decimal::zero();

        foreach ($this->openOrders() as $order) {
            if ($order->side === OrderSide::Buy) {
                $total = $total->add($order->remaining()->mul($order->price));
            }
        }

        return $total;
    }

    public function reservedSellQuantity(): Decimal
    {
        $total = Decimal::zero();

        foreach ($this->openOrders() as $order) {
            if ($order->side === OrderSide::Sell) {
                $total = $total->add($order->remaining());
            }
        }

        return $total;
    }

    /** @return list<SimulatedOrder> */
    public function allOrders(): array
    {
        return array_values($this->orders);
    }
}
