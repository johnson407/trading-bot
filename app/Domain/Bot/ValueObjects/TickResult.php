<?php

namespace App\Domain\Bot\ValueObjects;

readonly class TickResult
{
    /** @param list<string> $errors */
    public function __construct(
        public string $status,
        public ?string $marketPrice = null,
        public int $ordersPlaced = 0,
        public int $fills = 0,
        public int $ordersRejected = 0,
        public array $errors = [],
        public int $durationMs = 0,
    ) {}

    public function successful(): bool
    {
        return $this->status === 'ok';
    }
}
