<?php

namespace App\Services\Engine;

use App\Domain\Support\Rfc3339;
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
     * Optional status fields are parsed best-effort. A missing or malformed
     * value is null, which the UI renders as unknown rather than false/off.
     *
     * @return array{
     *     available: bool,
     *     checked_at: string,
     *     last_seen_at: ?string,
     *     running_bots: ?int,
     *     started_at: ?string,
     *     live_trading: ?bool,
     *     market_data: ?array{
     *         configured: ?bool,
     *         mode: ?string,
     *         degraded: ?bool,
     *         reason: ?string,
     *         symbols: ?int,
     *         last_message_at: ?string
     *     }
     * }
     */
    public function status(): array
    {
        $now = CarbonImmutable::now('UTC');
        $inspected = [
            'available' => false,
            'checked_at' => $now->toIso8601String(),
            'last_seen_at' => null,
            'running_bots' => null,
            'started_at' => null,
            'live_trading' => null,
            'market_data' => null,
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
        $inspected['started_at'] = $this->parseUtcTimestamp($decoded['started_at'] ?? null);

        $liveTrading = $decoded['live_trading'] ?? null;
        $inspected['live_trading'] = is_bool($liveTrading) ? $liveTrading : null;
        $inspected['market_data'] = $this->parseMarketData($decoded['market_data'] ?? null);

        $inspected['available'] = $seen->lessThanOrEqualTo($now->addSeconds(self::FUTURE_SKEW_SECONDS))
            && $seen->greaterThanOrEqualTo($now->subSeconds(self::TTL_SECONDS));

        return $inspected;
    }

    public function available(): bool
    {
        return $this->status()['available'];
    }

    private function parseUtcTimestamp(mixed $value): ?string
    {
        return Rfc3339::toUtcIso($value);
    }

    /**
     * @return ?array{
     *     configured: ?bool,
     *     mode: ?string,
     *     degraded: ?bool,
     *     reason: ?string,
     *     symbols: ?int,
     *     last_message_at: ?string
     * }
     */
    private function parseMarketData(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $configured = $value['configured'] ?? null;
        $degraded = $value['degraded'] ?? null;
        $mode = $value['mode'] ?? null;
        $reason = $value['reason'] ?? null;
        $symbols = $value['symbols'] ?? null;

        return [
            'configured' => is_bool($configured) ? $configured : null,
            'mode' => is_string($mode) ? $mode : null,
            'degraded' => is_bool($degraded) ? $degraded : null,
            'reason' => is_string($reason) ? $reason : null,
            'symbols' => is_int($symbols) ? $symbols : null,
            'last_message_at' => $this->parseUtcTimestamp($value['last_message_at'] ?? null),
        ];
    }
}
