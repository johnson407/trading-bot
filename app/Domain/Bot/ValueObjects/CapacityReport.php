<?php

namespace App\Domain\Bot\ValueObjects;

readonly class CapacityReport
{
    public function __construct(
        public int $bots,
        public int $rounds,
        public int $ticks,
        public int $errors,
        public float $averageRoundMs,
        public float $maxRoundMs,
        public bool $withinBudget,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bots' => $this->bots,
            'rounds' => $this->rounds,
            'ticks' => $this->ticks,
            'errors' => $this->errors,
            'average_round_ms' => round($this->averageRoundMs, 2),
            'max_round_ms' => round($this->maxRoundMs, 2),
            'within_budget' => $this->withinBudget,
        ];
    }
}
