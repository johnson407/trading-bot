<?php

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use RuntimeException;

class InvalidOrderTransitionException extends RuntimeException
{
    public static function from(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Cannot transition order from [{$from->value}] to [{$to->value}].");
    }
}
