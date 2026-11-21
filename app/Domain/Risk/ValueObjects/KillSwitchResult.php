<?php

namespace App\Domain\Risk\ValueObjects;

readonly class KillSwitchResult
{
    /**
     * @param  list<int>  $botIds
     * @param  list<array{bot_id: int, canceled: int, failed: int, failures: list<array{order_id: int, message: string}>}>  $results
     */
    public function __construct(
        public array $botIds,
        public array $results,
    ) {}

    public function failures(): int
    {
        return array_sum(array_column($this->results, 'failed'));
    }

    public function canceled(): int
    {
        return array_sum(array_column($this->results, 'canceled'));
    }

    public function hasFailures(): bool
    {
        return $this->failures() > 0;
    }
}
