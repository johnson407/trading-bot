<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final readonly class GridLevelPlan
{
    public function __construct(
        public int $sequence,
        public Decimal $price,
        public OrderSide $side,
    ) {
    }

    public static function make(int $sequence, string|Decimal $price, OrderSide|string $side): self
    {
        return new self(
            sequence: $sequence,
            price: Decimal::of($price),
            side: $side instanceof OrderSide ? $side : OrderSide::from($side),
        );
    }
}
