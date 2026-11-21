<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final readonly class FillEvent
{
    public function __construct(
        public string $exchangeFillId,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $fee,
        public ?string $clientOrderId = null,
        public ?int $gridLevelSequence = null,
    ) {
    }

    public static function make(
        string $exchangeFillId,
        OrderSide|string $side,
        string|Decimal $price,
        string|Decimal $quantity,
        string|Decimal $fee = '0',
        ?string $clientOrderId = null,
        ?int $gridLevelSequence = null,
    ): self {
        return new self(
            exchangeFillId: $exchangeFillId,
            side: $side instanceof OrderSide ? $side : OrderSide::from($side),
            price: Decimal::of($price),
            quantity: Decimal::of($quantity),
            fee: Decimal::of($fee),
            clientOrderId: $clientOrderId,
            gridLevelSequence: $gridLevelSequence,
        );
    }
}
