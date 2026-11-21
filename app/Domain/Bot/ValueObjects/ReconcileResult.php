<?php

namespace App\Domain\Bot\ValueObjects;

readonly class ReconcileResult
{
    /** @param list<string> $conflicts @param list<string> $errors */
    public function __construct(
        public string $status,
        public int $matched = 0,
        public int $filled = 0,
        public int $canceled = 0,
        public array $conflicts = [],
        public array $errors = [],
    ) {}

    public static function skipped(): self
    {
        return new self('skipped');
    }

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
