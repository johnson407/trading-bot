<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final readonly class OrderIntent
{
    public function __construct(
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public ?int $gridLevelSequence = null,
        public string $reason = 'strategy.tick',
    ) {
    }

    public static function limit(
        OrderSide|string $side,
        string|Decimal $price,
        string|Decimal $quantity,
        ?int $gridLevelSequence = null,
        string $reason = 'strategy.tick',
    ): self {
        return new self(
            side: $side instanceof OrderSide ? $side : OrderSide::from($side),
            price: Decimal::of($price),
            quantity: Decimal::of($quantity),
            gridLevelSequence: $gridLevelSequence,
            reason: $reason,
        );
    }

    public function opposite(): self
    {
        return new self(
            side: $this->side->opposite(),
            price: $this->price,
            quantity: $this->quantity,
            gridLevelSequence: $this->gridLevelSequence,
            reason: 'strategy.opposite',
        );
    }
}
