<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final readonly class OpenOrderSnapshot
{
    public function __construct(
        public string $clientOrderId,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public ?int $gridLevelSequence = null,
    ) {
    }

    public static function make(
        string $clientOrderId,
        OrderSide|string $side,
        string|Decimal $price,
        string|Decimal $quantity,
        ?int $gridLevelSequence = null,
    ): self {
        return new self(
            clientOrderId: $clientOrderId,
            side: $side instanceof OrderSide ? $side : OrderSide::from($side),
            price: Decimal::of($price),
            quantity: Decimal::of($quantity),
            gridLevelSequence: $gridLevelSequence,
        );
    }
}
