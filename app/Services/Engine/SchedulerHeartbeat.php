<?php

namespace App\Services\Engine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeat
{
    public const KEY = 'scheduler:heartbeat';

    public const GRACE_SECONDS = 180;

    public function record(): void
    {
        Cache::put(self::KEY, now()->toIso8601String(), now()->addSeconds(self::GRACE_SECONDS));
    }

    public function lastSeenAt(): ?Carbon
    {
        $value = Cache::get(self::KEY);

        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function fresh(): bool
    {
        $lastSeenAt = $this->lastSeenAt();

        return $lastSeenAt !== null
            && $lastSeenAt->diffInSeconds(now(), true) <= self::GRACE_SECONDS;
    }
}
