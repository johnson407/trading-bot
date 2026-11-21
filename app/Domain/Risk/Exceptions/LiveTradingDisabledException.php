<?php

namespace App\Domain\Risk\Exceptions;

use RuntimeException;

class LiveTradingDisabledException extends RuntimeException
{
    public static function forBot(int $botId): self
    {
        return new self("Live trading is disabled by configuration; bot {$botId} cannot start in live mode.");
    }
}
