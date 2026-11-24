<?php

namespace Tests\Support;

use App\Services\Engine\EngineHeartbeat;

class FakeEngineHeartbeat extends EngineHeartbeat
{
    public bool $available = true;

    public ?string $lastSeenAt = null;

    public ?int $runningBots = null;

    public function __construct() {}

    public function available(): bool
    {
        return $this->available;
    }

    /** @return array{available:bool,checked_at:string,last_seen_at:?string,running_bots:?int} */
    public function status(): array
    {
        return [
            'available' => $this->available,
            'checked_at' => now('UTC')->toIso8601String(),
            'last_seen_at' => $this->lastSeenAt,
            'running_bots' => $this->runningBots,
        ];
    }
}
