<?php

namespace App\Domain\Strategy\ValueObjects;

final readonly class StrategyState
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $version = 1,
        public array $data = [],
    ) {
    }

    public static function empty(int $version = 1): self
    {
        return new self($version, []);
    }

    /** @param array<string, mixed>|null $payload */
    public static function fromArray(?array $payload, int $fallbackVersion = 1): self
    {
        if ($payload === null) {
            return self::empty($fallbackVersion);
        }

        return new self(
            version: (int) ($payload['version'] ?? $fallbackVersion),
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
        );
    }

    public function isNew(): bool
    {
        return $this->data === [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @param array<string, mixed> $data */
    public function with(array $data): self
    {
        return new self($this->version, $data);
    }

    /** @param array<string, mixed> $values */
    public function merge(array $values): self
    {
        return new self($this->version, array_replace($this->data, $values));
    }

    /** @return array{version: int, data: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'data' => $this->data,
        ];
    }
}
