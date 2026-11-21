<?php

namespace App\Domain\Bot;

use App\Domain\Bot\ValueObjects\CapacityReport;

/**
 * Measures whether a set of bots can each be ticked inside a one-second budget.
 *
 * The harness deliberately does not sleep between rounds. It measures the time
 * spent ticking every bot in a round and compares the slowest round to the
 * configured one-second interval, which is the schedule-drift signal.
 */
class BotCapacityHarness
{
    public function __construct(private readonly BotTickPipeline $pipeline) {}

    /**
     * @param  iterable<\App\Models\Bot>  $bots
     */
    public function run(iterable $bots, int $rounds = 10, int $budgetMs = 1000): CapacityReport
    {
        $bots = collect($bots)->values();
        $ticks = 0;
        $errors = 0;
        $roundTimes = [];

        for ($round = 0; $round < $rounds; $round++) {
            $started = hrtime(true);

            foreach ($bots as $bot) {
                $result = $this->pipeline->tick($bot->fresh());
                $ticks++;

                if (! $result->successful()) {
                    $errors++;
                }
            }

            $roundTimes[] = (hrtime(true) - $started) / 1_000_000;
        }

        $average = $roundTimes === [] ? 0.0 : array_sum($roundTimes) / count($roundTimes);
        $max = $roundTimes === [] ? 0.0 : max($roundTimes);

        return new CapacityReport(
            bots: $bots->count(),
            rounds: $rounds,
            ticks: $ticks,
            errors: $errors,
            averageRoundMs: $average,
            maxRoundMs: $max,
            withinBudget: $max <= $budgetMs,
        );
    }
}
