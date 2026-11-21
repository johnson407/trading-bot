<?php

namespace App\Domain\Exchange\ValueObjects;

use App\Domain\Support\Decimal;

readonly class Balance
{
    public function __construct(
        public string $asset,
        public Decimal $free,
        public Decimal $used,
        public Decimal $total,
    ) {}

    public static function make(string $asset, Decimal|string $free, Decimal|string $used = '0', Decimal|string|null $total = null): self
    {
        $free = Decimal::of($free);
        $used = Decimal::of($used);

        return new self($asset, $free, $used, $total === null ? $free->add($used) : Decimal::of($total));
    }
}
