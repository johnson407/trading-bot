<?php

namespace App\Domain\Order\ValueObjects;

use App\Domain\Support\Decimal;
use DateTimeImmutable;

readonly class FillData
{
    public function __construct(
        public string $exchange,
        public string $exchangeFillId,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $fee,
        public string $feeCurrency = 'USDT',
        public ?DateTimeImmutable $filledAt = null,
    ) {
    }

    public static function make(
        string $exchange,
        string $exchangeFillId,
        Decimal|string $price,
        Decimal|string $quantity,
        Decimal|string $fee = '0',
        string $feeCurrency = 'USDT',
        ?DateTimeImmutable $filledAt = null,
    ): self {
        $price = Decimal::of($price);
        $quantity = Decimal::of($quantity);

        if (! $price->isPositive()) {
            throw new \InvalidArgumentException('Fill price must be positive.');
        }

        if (! $quantity->isPositive()) {
            throw new \InvalidArgumentException('Fill quantity must be positive.');
        }

        return new self($exchange, $exchangeFillId, $price, $quantity, Decimal::of($fee), $feeCurrency, $filledAt);
    }
}
