<?php

namespace App\Domain\Strategy\Exceptions;

use RuntimeException;

class UnknownStrategyException extends RuntimeException
{
    /** @param list<string> $known */
    public static function forKey(string $key, array $known = []): self
    {
        $suffix = $known === [] ? '' : ' Known strategies: '.implode(', ', $known).'.';

        return new self("Unknown strategy [{$key}].{$suffix}");
    }
}
