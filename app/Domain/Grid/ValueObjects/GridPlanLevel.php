<?php

namespace App\Domain\Grid\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final readonly class GridPlanLevel
{
    public function __construct(
        public int $sequence,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $notional,
    ) {}

    /** @return array{sequence: int, side: string, price: string, quantity: string, notional: string} */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'side' => $this->side->value,
            'price' => $this->price->toString(),
            'quantity' => $this->quantity->toString(),
            'notional' => $this->notional->toString(),
        ];
    }
}
