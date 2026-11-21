<?php

namespace App\Domain\Risk\ValueObjects;

use App\Models\Bot;

readonly class LiveStartConfirmation
{
    public function __construct(public string $confirmation) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $value = $data['confirmation'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return new self(trim($value));
    }

    public function matches(Bot $bot): bool
    {
        return $this->confirmation === $this->phrase($bot);
    }

    public static function phrase(Bot $bot): string
    {
        return "{$bot->name} on {$bot->exchange}";
    }
}
