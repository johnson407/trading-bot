<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Support\Decimal;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;

final readonly class StrategyConfiguration
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public string $key,
        public array $values = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value) || $value === '') {
            throw InvalidStrategyConfigurationException::forStrategy($this->key, "[{$key}] must be a non-empty string");
        }

        return $value;
    }

    public function decimal(string $key, string|Decimal|null $default = null): Decimal
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key, "[{$key}] is required");
        }

        return Decimal::of($value);
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key, "[{$key}] must be an integer");
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw InvalidStrategyConfigurationException::forStrategy($this->key, "[{$key}] must be a boolean");
        }

        return $value;
    }
}
