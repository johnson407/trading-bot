<?php

namespace App\Domain\Exchange\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

readonly class PaperFill
{
    public function __construct(
        public ?int $orderId,
        public string $clientOrderId,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $fee,
        public string $feeCurrency,
    ) {}
}
