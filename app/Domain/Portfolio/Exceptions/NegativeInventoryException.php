<?php

namespace App\Domain\Portfolio\Exceptions;

use RuntimeException;

class NegativeInventoryException extends RuntimeException
{
    public static function forSale(string $available, string $requested, int $botId): self
    {
        return new self("Bot [{$botId}] cannot sell {$requested} base units; only {$available} available.");
    }
}
