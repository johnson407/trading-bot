<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;
use DateTimeImmutable;

final readonly class SimulatedFill
{
    public function __construct(
        public int $sequence,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $fee,
        public string $feeCurrency,
        public DateTimeImmutable $filledAt,
        public ?string $clientOrderId = null,
        public ?int $gridLevelSequence = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'side' => $this->side->value,
            'price' => $this->price->toString(),
            'quantity' => $this->quantity->toString(),
            'fee' => $this->fee->toString(),
            'fee_currency' => $this->feeCurrency,
            'filled_at' => $this->filledAt->format(DATE_ATOM),
            'client_order_id' => $this->clientOrderId,
            'grid_level_sequence' => $this->gridLevelSequence,
        ];
    }
}
