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
        $cutoff = $now->copy()->subSeconds((int) config('trading.tick.stale_after_seconds', 15));

        $stale = Bot::query()
            ->running()
            ->whereRaw('COALESCE(last_successful_tick_at, last_tick_at, created_at) < ?', [$cutoff])
            ->get();

        foreach ($stale as $bot) {
            $last = $bot->last_successful_tick_at ?? $bot->last_tick_at ?? $bot->created_at;

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
