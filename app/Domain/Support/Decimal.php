<?php

namespace App\Domain\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable decimal value backed by bcmath.
 *
 * Floats are rejected on purpose: binary floating point must never enter
 * monetary calculations. Values are kept as strings with up to 16 decimal
 * places.
 */
final class Decimal implements Stringable
{
    public const SCALE = 16;

    public const ROUND_HALF_UP = 'half_up';

    public const ROUND_HALF_DOWN = 'half_down';

    public const ROUND_DOWN = 'down';

    public const ROUND_UP = 'up';

    private function __construct(private readonly string $value)
    {
    }

    public static function of(mixed $value): self
    {
        if ($value instanceof self) {
            return new self($value->value);
        }

        if (is_float($value) || is_bool($value) || $value === null || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('Only decimal strings and integers are allowed.');
        }

        return new self(self::normalize((string) $value));
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(self|string|int $other): self
    {
        return self::fromBc(bcadd($this->value, self::of($other)->value, self::SCALE));
    }

    public function sub(self|string|int $other): self
    {
        return self::fromBc(bcsub($this->value, self::of($other)->value, self::SCALE));
    }

    public function mul(self|string|int $other): self
    {
        return self::fromBc(bcmul($this->value, self::of($other)->value, self::SCALE));
    }

    public function div(self|string|int $other): self
    {
        $divisor = self::of($other);

        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        // Compute one extra digit and round half-up at the public scale.
        return self::fromBc(bcdiv($this->value, $divisor->value, self::SCALE + 1))
            ->round(self::SCALE);
    }

    public function pow(int $exponent): self
    {
        if ($exponent < 0) {
            throw new InvalidArgumentException('Exponent must not be negative.');
        }

        return self::fromBc(bcpow($this->value, (string) $exponent, self::SCALE));
    }

    public function compare(self|string|int $other): int
    {
        return bccomp($this->value, self::of($other)->value, self::SCALE);
    }

    public function equals(self|string|int $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(self|string|int $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(self|string|int $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function min(self|string|int $other): self
    {
        return $this->lessThanOrEqual($other) ? $this : self::of($other);
    }

    public function max(self|string|int $other): self
    {
        return $this->greaterThanOrEqual($other) ? $this : self::of($other);
    }

    public function abs(): self
    {
        return $this->isNegative() ? new self(substr($this->value, 1)) : $this;
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self($this->isNegative() ? substr($this->value, 1) : '-'.$this->value);
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function isPositive(): bool
    {
        return ! $this->isZero() && ! $this->isNegative();
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    /**
     * Round to a number of decimal places. Rounding is applied to the
     * magnitude, so half-up means "away from zero" for negatives.
     */
    public function round(int $scale = self::SCALE, string $mode = self::ROUND_HALF_UP): self
    {
        if ($scale < 0 || $scale > self::SCALE) {
            throw new InvalidArgumentException('Scale must be between 0 and '.self::SCALE.'.');
        }

        $negative = $this->isNegative();
        $absolute = $this->abs()->value;
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');

        $kept = substr($fraction, 0, $scale);
        $next = (int) $fraction[$scale];
        $hasMore = trim(substr($fraction, $scale + 1), '0') !== '';

        $increment = match ($mode) {
            self::ROUND_DOWN => false,
            self::ROUND_UP => $next > 0 || $hasMore,
            self::ROUND_HALF_DOWN => $next > 5 || ($next === 5 && $hasMore),
            self::ROUND_HALF_UP => $next >= 5,
            default => throw new InvalidArgumentException('Unknown rounding mode: '.$mode),
        };

        if ($increment) {
            [$integer, $kept] = self::increment($integer, $kept);
        }

        $rounded = $integer.($scale > 0 ? '.'.$kept : '');
        $rounded = self::normalize($rounded);

        return new self($negative && $rounded !== '0' ? '-'.$rounded : $rounded);
    }

    /**
     * Return exactly $scale decimal places, padding with zeros.
     */
    public function toFixed(int $scale = self::SCALE): string
    {
        if ($scale < 0 || $scale > self::SCALE) {
            throw new InvalidArgumentException('Scale must be between 0 and '.self::SCALE.'.');
        }

        $negative = $this->isNegative();
        $absolute = $this->abs()->value;
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, $scale, '0');

        $result = $integer.($scale > 0 ? '.'.$fraction : '');

        return $negative && $result !== '0' ? '-'.$result : $result;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function fromBc(string $value): self
    {
        return new self(self::normalize($value));
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal value: '.$value);
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        $normalized = $fraction === '' ? $integer : $integer.'.'.$fraction;
        $normalized = $normalized === '0' ? '0' : $normalized;

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function increment(string $integer, string $fraction): array
    {
        $combined = $integer.$fraction;
        $carry = 1;
        $result = '';

        for ($i = strlen($combined) - 1; $i >= 0; $i--) {
            $digit = (int) $combined[$i] + $carry;
            $result = ($digit % 10).$result;
            $carry = intdiv($digit, 10);
        }

        if ($carry > 0) {
            $result = $carry.$result;
        }

        $integerLength = strlen($result) - strlen($fraction);

        if ($integerLength <= 0) {
            $result = str_pad($result, strlen($fraction) + 1, '0', STR_PAD_LEFT);
            $integerLength = 1;
        }

        return [substr($result, 0, $integerLength), substr($result, $integerLength)];
    }
}
