<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Support\Decimal;
use DateTimeImmutable;

final readonly class MarketSnapshot
{
    /** @param list<string> $recentPrices */
    public function __construct(
        public string $symbol,
        public Decimal $price,
        public ?Decimal $bid = null,
        public ?Decimal $ask = null,
        public array $recentPrices = [],
        public ?DateTimeImmutable $timestamp = null,
    ) {
    }

    /** @param list<string|Decimal> $recentPrices */
    public static function make(
        string $symbol,
        string|Decimal $price,
        string|Decimal|null $bid = null,
        string|Decimal|null $ask = null,
        array $recentPrices = [],
    ): self {
        return new self(
            symbol: $symbol,
            price: Decimal::of($price),
            bid: $bid === null ? null : Decimal::of($bid),
            ask: $ask === null ? null : Decimal::of($ask),
            recentPrices: array_map(fn ($value) => Decimal::of($value)->toString(), $recentPrices),
            timestamp: new DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function priceString(): string
    {
        return $this->price->toString();
    }
}
