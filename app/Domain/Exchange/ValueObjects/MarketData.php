<?php

namespace App\Domain\Exchange\ValueObjects;

use App\Domain\Support\Decimal;
use DateTimeImmutable;

readonly class MarketData
{
    public function __construct(
        public string $symbol,
        public Decimal $last,
        public ?Decimal $bid,
        public ?Decimal $ask,
        public int $pricePrecision,
        public int $amountPrecision,
        public Decimal $minAmount,
        public Decimal $minNotional,
        public ?DateTimeImmutable $timestamp = null,
    ) {}

    public static function make(
        string $symbol,
        Decimal|string $last,
        Decimal|string|null $bid = null,
        Decimal|string|null $ask = null,
        int $pricePrecision = 8,
        int $amountPrecision = 8,
        Decimal|string $minAmount = '0',
        Decimal|string $minNotional = '0',
        ?DateTimeImmutable $timestamp = null,
    ): self {
        $last = Decimal::of($last);

        if (! $last->isPositive()) {
            throw new \InvalidArgumentException('Market price must be positive.');
        }

        return new self(
            $symbol,
            $last,
            $bid === null ? null : Decimal::of($bid),
            $ask === null ? null : Decimal::of($ask),
            $pricePrecision,
            $amountPrecision,
            Decimal::of($minAmount),
            Decimal::of($minNotional),
            $timestamp,
        );
    }
}
