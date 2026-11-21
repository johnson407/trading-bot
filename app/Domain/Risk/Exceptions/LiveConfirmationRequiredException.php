<?php

namespace App\Domain\Risk\Exceptions;

use RuntimeException;

class LiveConfirmationRequiredException extends RuntimeException
{
    public static function forBot(int $botId, string $name): self
    {
        return new self("Live mode requires confirmation text matching the bot name \"{$name}\" for bot {$botId}.");
    }
}
