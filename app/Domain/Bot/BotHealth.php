<?php

namespace App\Domain\Bot;

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Support\Rfc3339;
use App\Models\Bot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class BotHealth
{
    public const MIN_STALE_SECONDS = 180;

    public const STALE_ALLOWANCE_SECONDS = 30;

    /**
     * Build the dashboard's "needs attention" rows from already-loaded bots.
     *
     * Healthy, idle, stopped, user-paused, and archived bots are omitted.
     *
     * @param  Collection<int, Bot>  $bots
     * @param  array{available?: bool, started_at?: ?string}  $heartbeat
     * @return list<array{
     *     id: int,
     *     name: string,
     *     mode: string,
     *     status: string,
     *     desired_status: string,
     *     last_tick_at: ?string,
     *     last_successful_tick_at: ?string,
     *     last_error: ?string,
     *     issues: list<'stale'|'paused_by_engine'|'error'>,
     *     reason: string,
     *     can_resume: bool
     * }>
     */
    public function forBots(Collection $bots, array $heartbeat, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $engineAvailable = ($heartbeat['available'] ?? false) === true;
        $engineStartedAt = $this->parseTimestamp($heartbeat['started_at'] ?? null);

        $rows = [];

        foreach ($bots as $bot) {
            if ($bot->trashed()) {
                continue;
            }

            $issues = [];
            $reasons = [];
            $lastError = $this->nonBlank($bot->last_error);
            $canResume = false;

            if ($bot->status === BotStatus::Paused && $bot->desired_status === BotStatus::Running) {
                $issues[] = 'paused_by_engine';
                $reasons[] = $lastError ?? 'Paused by the engine. Resume when the cause is resolved.';
                $canResume = true;
            }

            if ($this->isStale($bot, $engineAvailable, $engineStartedAt, $now)) {
                $issues[] = 'stale';
                $reasons[] = 'No successful tick within the stale threshold.';
            }

            if ($bot->status === BotStatus::Error || ($bot->status === BotStatus::Running && $lastError !== null)) {
                $issues[] = 'error';
                $reasons[] = $lastError ?? 'The bot is in an error state.';
            }

            if ($issues === []) {
                continue;
            }

            $rows[] = [
                'id' => $bot->id,
                'name' => $bot->name,
                'mode' => $bot->mode->value,
                'status' => $bot->status->value,
                'desired_status' => $bot->desired_status->value,
                'last_tick_at' => $bot->last_tick_at?->toIso8601String(),
                'last_successful_tick_at' => $bot->last_successful_tick_at?->toIso8601String(),
                'last_error' => $lastError,
                'issues' => $issues,
                'reason' => implode(' ', $reasons),
                'can_resume' => $canResume,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            $leftPriority = $this->priority($left['issues']);
            $rightPriority = $this->priority($right['issues']);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return ($right['last_tick_at'] ?? '') <=> ($left['last_tick_at'] ?? '');
        });

        return $rows;
    }

    private function isStale(
        Bot $bot,
        bool $engineAvailable,
        ?CarbonImmutable $engineStartedAt,
        CarbonImmutable $now,
    ): bool {
        if (! $engineAvailable || $engineStartedAt === null) {
            return false;
        }

        if ($bot->status !== BotStatus::Running || $bot->desired_status !== BotStatus::Running) {
            return false;
        }

        $threshold = $this->staleThreshold($bot);

        if ($engineStartedAt->greaterThanOrEqualTo($now->subSeconds($threshold))) {
            return false;
        }

        $lastActivity = $bot->last_successful_tick_at ?? $bot->last_tick_at ?? $bot->created_at;

        if ($lastActivity === null) {
            return false;
        }

        return CarbonImmutable::instance($lastActivity)
            ->addSeconds($threshold)
            ->lessThan($now);
    }

    private function staleThreshold(Bot $bot): int
    {
        $interval = max(1, (int) $bot->tick_interval_seconds);

        return max(self::MIN_STALE_SECONDS, $interval + self::STALE_ALLOWANCE_SECONDS);
    }

    private function parseTimestamp(?string $value): ?CarbonImmutable
    {
        return Rfc3339::parse($value);
    }

    private function nonBlank(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  list<'stale'|'paused_by_engine'|'error'>  $issues
     */
    private function priority(array $issues): int
    {
        if (in_array('paused_by_engine', $issues, true)) {
            return 0;
        }

        if (in_array('stale', $issues, true)) {
            return 1;
        }

        return 2;
    }
}
