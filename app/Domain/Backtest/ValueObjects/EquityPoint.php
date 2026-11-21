<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Support\Decimal;
use DateTimeImmutable;

final readonly class EquityPoint
{
    public function __construct(
        public DateTimeImmutable $at,
        public Decimal $equity,
    ) {}

    /** @return array{at: string, equity: string} */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format(DATE_ATOM),
            'equity' => $this->equity->toString(),
        ];
    }
}
