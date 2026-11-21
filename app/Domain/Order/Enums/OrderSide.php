<?php

namespace App\Domain\Order\Enums;

enum OrderSide: string
{
    case Buy = 'buy';
    case Sell = 'sell';

    public function opposite(): self
    {
        return $this === self::Buy ? self::Sell : self::Buy;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
