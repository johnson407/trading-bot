<?php

namespace App\Domain\Exchange\ValueObjects;

use App\Domain\Support\Decimal;
use DateTimeImmutable;

readonly class Candle
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public Decimal $close,
    ) {}
}
