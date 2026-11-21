<?php

namespace App\Domain\Strategy\Exceptions;

use RuntimeException;

class InvalidStrategyStateException extends RuntimeException
{
    public static function forStrategy(string $key, string $reason): self
    {
        return new self("Strategy [{$key}] cannot resume: {$reason}");
    }
}
