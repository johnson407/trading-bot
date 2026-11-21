<?php

namespace App\Domain\Exchange\ValueObjects;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use DateTimeImmutable;

readonly class ExchangeOrder
{
    public function __construct(
        public string $exchangeOrderId,
        public string $clientOrderId,
        public OrderStatus $status,
        public OrderSide $side,
        public Decimal $price,
        public Decimal $quantity,
        public Decimal $filledQuantity,
        public Decimal $averagePrice,
        public Decimal $fee,
        public ?DateTimeImmutable $updatedAt = null,
        public string $feeCurrency = 'USDT',
    ) {}

    public static function make(
        string $exchangeOrderId,
        string $clientOrderId,
        OrderStatus $status,
        OrderSide $side,
        Decimal|string $price,
        Decimal|string $quantity,
        Decimal|string $filledQuantity = '0',
        Decimal|string|null $averagePrice = null,
        ?DateTimeImmutable $updatedAt = null,
        Decimal|string $fee = '0',
        string $feeCurrency = 'USDT',
    ): self {
        $price = Decimal::of($price);
        $quantity = Decimal::of($quantity);
        $filled = Decimal::of($filledQuantity);

        return new self(
            $exchangeOrderId,
            $clientOrderId,
            $status,
            $side,
            $price,
            $quantity,
            $filled,
            $averagePrice === null ? Decimal::zero() : Decimal::of($averagePrice),
            Decimal::of($fee),
            $updatedAt,
            $feeCurrency,
        );
    }
}
