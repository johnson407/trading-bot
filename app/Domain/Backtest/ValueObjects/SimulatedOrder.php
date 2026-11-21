<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;

final class SimulatedOrder
{
    public function __construct(
        public readonly string $clientOrderId,
        public readonly OrderSide $side,
        public readonly Decimal $price,
        public readonly Decimal $quantity,
        public readonly ?int $gridLevelSequence,
        public readonly int $placedCandleIndex,
        public Decimal $filledQuantity,
        public OrderStatus $status = OrderStatus::Open,
    ) {}

    public function remaining(): Decimal
    {
        return $this->quantity->sub($this->filledQuantity);
    }

    public function isOpen(): bool
    {
        return $this->status === OrderStatus::Open
            || $this->status === OrderStatus::PartiallyFilled;
    }

    public function isEligible(int $candleIndex): bool
    {
        return $this->isOpen() && $this->placedCandleIndex < $candleIndex;
    }
}
