<?php

namespace App\Domain\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Strict RFC 3339 timestamp parsing for engine-supplied optional fields.
 *
 * Carbon's parser accepts date-only, relative, and offset-less strings. Those
 * values are not valid RFC 3339 timestamps and must be treated as unknown.
 */
final class Rfc3339
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:[Zz]|[+-](\d{2}):(\d{2}))$/';

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $matches) !== 1) {
            return null;
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        if ((int) $matches[4] > 23 || (int) $matches[5] > 59 || (int) $matches[6] > 59) {
            return null;
        }

        if (isset($matches[7], $matches[8]) && ((int) $matches[7] > 23 || (int) $matches[8] > 59)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public static function toUtcIso(mixed $value): ?string
    {
        return self::parse($value)?->toIso8601String();
    }
}
