<?php

namespace App\Domain\Strategy\Exceptions;

use InvalidArgumentException;

class InvalidStrategyConfigurationException extends InvalidArgumentException
{
    public static function forStrategy(string $key, string $reason): self
    {
        return new self("Invalid configuration for strategy [{$key}]: {$reason}");
    }
}
