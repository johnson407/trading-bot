<?php

namespace App\Domain\Support;

use InvalidArgumentException;

/**
 * Converts exchange-provided numbers (often floats) into plain decimal
 * strings. Floats must never reach Decimal::of() because PHP's string cast
 * can emit scientific notation such as "1.0E-5", which the decimal parser
 * rejects.
 */
final class Number
{
    public static function decimalString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return preg_match('/[eE]/', $trimmed) === 1
                ? self::expandExponent($trimmed)
                : $trimmed;
        }

        if (! is_float($value)) {
            throw new InvalidArgumentException('Only integers, floats and decimal strings are supported.');
        }

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Non-finite numbers cannot be converted to decimals.');
        }

        $encoded = json_encode($value);

        if (is_string($encoded) && preg_match('/[eE]/', $encoded) !== 1) {
            return self::trim($encoded);
        }

        return self::trim(sprintf('%.18F', $value));
    }

    private static function expandExponent(string $value): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $value, $matches) !== 1) {
            return $value;
        }

        $sign = $matches[1];
        $integer = $matches[2];
        $fraction = $matches[3];
        $exponent = (int) $matches[4];
        $digits = $integer.$fraction;
        $point = strlen($integer) + $exponent;

        if ($point <= 0) {
            $digits = str_repeat('0', -$point).$digits;
            $point = 0;
        }

        if ($point >= strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }

        $plain = substr($digits, 0, $point);

        if ($plain === '') {
            $plain = '0';
        }

        if ($point < strlen($digits)) {
            $plain .= '.'.substr($digits, $point);
        }

        return self::trim($sign.$plain);
    }

    private static function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
