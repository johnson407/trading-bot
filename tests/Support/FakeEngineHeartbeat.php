<?php

namespace Tests\Support;

use App\Services\Engine\EngineHeartbeat;

class FakeEngineHeartbeat extends EngineHeartbeat
{
    public bool $available = true;

    public ?string $lastSeenAt = null;

    public ?int $runningBots = null;

    public ?string $startedAt = null;

    public ?bool $liveTrading = null;

    /** @var ?array{configured:?bool,mode:?string,degraded:?bool,reason:?string,symbols:?int,last_message_at:?string} */
    public ?array $marketData = null;

    public function __construct() {}

    public function available(): bool
    {
        return $this->available;
    }

    /**
     * @return array{
     *     available:bool,
     *     checked_at:string,
     *     last_seen_at:?string,
     *     running_bots:?int,
     *     started_at:?string,
     *     live_trading:?bool,
     *     market_data:?array{configured:?bool,mode:?string,degraded:?bool,reason:?string,symbols:?int,last_message_at:?string}
     * }
     */
    public function status(): array
    {
        return [
            'available' => $this->available,
            'checked_at' => now('UTC')->toIso8601String(),
            'last_seen_at' => $this->lastSeenAt,
            'running_bots' => $this->runningBots,
            'started_at' => $this->startedAt,
            'live_trading' => $this->liveTrading,
            'market_data' => $this->marketData,
        ];
    }
}
