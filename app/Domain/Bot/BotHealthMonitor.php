<?php

namespace App\Domain\Bot;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\Bot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BotHealthMonitor
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RiskEventRecorder $riskEvents,
    ) {}

    /**
     * Pause running bots whose last successful tick is stale.
     *
     * @return list<Bot>
     */
    public function check(?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now('UTC');
        $staleAfter = (int) config('trading.tick.stale_after_seconds', 180);
        $healthInterval = (int) config('trading.health.check_interval_seconds', 30);

        $effectiveThreshold = fn (Bot $bot): int => max(
            $staleAfter,
            max(1, (int) $bot->tick_interval_seconds) + $healthInterval,
        );

        $stale = Bot::query()
            ->running()
            ->get()
            ->filter(function (Bot $bot) use ($now, $effectiveThreshold): bool {
                $last = $bot->last_successful_tick_at ?? $bot->last_tick_at ?? $bot->created_at;

                return $last !== null
                    && $last->lt($now->copy()->subSeconds($effectiveThreshold($bot)));
            })
            ->values();

        foreach ($stale as $bot) {
            $last = $bot->last_successful_tick_at ?? $bot->last_tick_at ?? $bot->created_at;
            $cutoff = $now->copy()->subSeconds($effectiveThreshold($bot));

            $bot->forceFill([
                'status' => BotStatus::Paused,
                'last_error' => 'No successful tick since '.($last?->toIso8601String() ?? 'unknown'),
            ])->save();

            $this->activity->record('bot.stale', 'Bot paused because its tick loop is stale', $bot, context: [
                'last_successful_tick_at' => $last?->toIso8601String(),
                'cutoff' => $cutoff->toIso8601String(),
            ], severity: 'error');

            $this->riskEvents->record('risk.stale_bot', 'Bot tick loop is stale', $bot, context: [
                'last_successful_tick_at' => $last?->toIso8601String(),
            ], severity: RiskSeverity::Warning);
        }

        return $stale->all();
    }
}
