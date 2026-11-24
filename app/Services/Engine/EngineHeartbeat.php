<?php

namespace App\Services\Engine;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

class EngineHeartbeat
{
    public const KEY = 'engine:heartbeat';

    public const TTL_SECONDS = 10;

    public const FUTURE_SKEW_SECONDS = 5;

    /**
     * Read the engine heartbeat for the dashboard and emergency checks.
     *
     * @return array{available: bool, checked_at: string, last_seen_at: ?string, running_bots: ?int}
     */
    public function status(): array
    {
        $now = CarbonImmutable::now('UTC');
        $inspected = [
            'available' => false,
            'checked_at' => $now->toIso8601String(),
            'last_seen_at' => null,
            'running_bots' => null,
        ];

        try {
            $raw = Redis::connection('engine')->get(self::KEY);
        } catch (Throwable) {
            return $inspected;
        }

        if (! is_string($raw) || $raw === '') {
            return $inspected;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_string($decoded['ts'] ?? null)) {
            return $inspected;
        }

        try {
            $seen = CarbonImmutable::parse($decoded['ts'])->utc();
        } catch (Throwable) {
            return $inspected;
        }

        $running = $decoded['running_bots'] ?? null;
        $inspected['last_seen_at'] = $seen->toIso8601String();
        $inspected['running_bots'] = is_int($running) ? $running : (is_numeric($running) ? (int) $running : null);
        $inspected['available'] = $seen->lessThanOrEqualTo($now->addSeconds(self::FUTURE_SKEW_SECONDS))
            && $seen->greaterThanOrEqualTo($now->subSeconds(self::TTL_SECONDS));

        return $inspected;
    }

    public function available(): bool
    {
        return $this->status()['available'];
    }
}
