<?php

namespace App\Domain\Risk\Exceptions;

use App\Models\Bot;
use InvalidArgumentException;

class LiveCredentialConflictException extends InvalidArgumentException
{
    public static function conflicting(Bot $bot): self
    {
        return new self(
            "Another live bot, {$bot->name}, is already active for this exchange credential. Stop it before creating or starting another live bot.",
        );
    }

    public static function missingCredential(): self
    {
        return new self('A live bot requires an exchange credential.');
    }
}
