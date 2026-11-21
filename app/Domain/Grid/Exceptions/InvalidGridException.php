<?php

namespace App\Domain\Grid\Exceptions;

use InvalidArgumentException;

class InvalidGridException extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self("Invalid grid: {$reason}");
    }
}
