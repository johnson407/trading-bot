<?php

namespace App\Actions;

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Support\Decimal;
use App\Models\BacktestRun;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Models\User;
use App\Services\Backtest\BacktestSnapshotBuilder;
use App\Services\Engine\CommandPublisher;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CreateBacktestSweep
{
    public function __construct(
        private readonly BacktestSnapshotBuilder $snapshots,
        private readonly CommandPublisher $commands,
    ) {}

    /**
     * Create a sweep and one pending run per cartesian combination, then
     * publish one engine command after the transaction commits.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function handle(
        User $user,
        Bot $bot,
        Timeframe $timeframe,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        array $parameters,
        ?string $name = null,
    ): BacktestSweep {
        if ($bot->user_id !== $user->id) {
            throw new InvalidArgumentException('The selected bot does not belong to this user.');
        }

        if (! in_array($bot->strategy_key, BacktestSweep::GRID_STRATEGIES, true)) {
            throw new InvalidArgumentException('Parameter sweeps require a grid strategy.');
        }

        $normalized = $this->normalizeParameters($parameters);
        $totalRuns = count($normalized['center_prices'])
            * count($normalized['spacing_values'])
            * count($normalized['level_counts']);
        $maximum = BacktestSweep::maximumRuns();

        if ($totalRuns < 2) {
            throw new InvalidArgumentException('A sweep must produce at least two runs.');
        }

        if ($totalRuns > $maximum) {
            throw new InvalidArgumentException("A sweep may produce at most {$maximum} runs.");
        }

        [$sweep, $runs] = DB::transaction(function () use (
            $user,
            $bot,
            $timeframe,
            $startAt,
            $endAt,
            $name,
            $normalized,
            $totalRuns,
        ): array {
            $sweep = BacktestSweep::query()->create([
                'user_id' => $user->id,
                'bot_id' => $bot->id,
                'name' => $name !== null && trim($name) !== '' ? trim($name) : $bot->name.' sweep',
                'symbol' => $bot->symbol,
                'timeframe' => $timeframe->value,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'parameters' => $normalized,
                'total_runs' => $totalRuns,
            ]);

            $runs = [];

            foreach ($normalized['center_prices'] as $center) {
                foreach ($normalized['spacing_values'] as $spacing) {
                    foreach ($normalized['level_counts'] as $levels) {
                        $snapshot = $this->snapshots->build(
                            $bot,
                            $timeframe,
                            $startAt,
                            $endAt,
                            [
                                'center_price' => $center,
                                'spacing_value' => $spacing,
                                'level_count' => $levels,
                                'levels_per_side' => $levels,
                            ],
                        );

                        $runs[] = BacktestRun::query()->create([
                            'user_id' => $user->id,
                            'bot_id' => $bot->id,
                            'sweep_id' => $sweep->id,
                            'name' => $this->runLabel($sweep, $center, $spacing, $levels),
                            'exchange' => $bot->exchange,
                            'symbol' => $bot->symbol,
                            'strategy_key' => $bot->strategy_key,
                            'timeframe' => $timeframe->value,
                            'start_at' => $startAt,
                            'end_at' => $endAt,
                            'capital_allocation' => $bot->capital_allocation,
                            'fee_rate' => $bot->fee_rate ?? '0',
                            'config_snapshot' => $snapshot,
                            'status' => BacktestStatus::Pending,
                            'progress' => 0,
                        ]);
                    }
                }
            }

            return [$sweep, $runs];
        });

        try {
            $this->commands->publish('backtest.sweep', $user->id, $bot->id, [
                'backtest_sweep_id' => $sweep->id,
            ]);
        } catch (Throwable $exception) {
            Log::error('backtest.sweep_publish_failed', [
                'backtest_sweep_id' => $sweep->id,
                'exception' => $exception,
            ]);

            foreach ($runs as $run) {
                $run->forceFill([
                    'status' => BacktestStatus::Failed,
                    'error' => 'The backtest job could not be queued.',
                    'finished_at' => now('UTC'),
                ])->save();
            }
        }

        return $sweep;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{center_prices: list<string>, spacing_values: list<string>, level_counts: list<int>}
     */
    private function normalizeParameters(array $parameters): array
    {
        return [
            'center_prices' => $this->normalizedDecimals($parameters['center_prices'] ?? null, 'center_prices'),
            'spacing_values' => $this->normalizedDecimals($parameters['spacing_values'] ?? null, 'spacing_values'),
            'level_counts' => $this->normalizedLevels($parameters['level_counts'] ?? null),
        ];
    }

    /** @return list<string> */
    private function normalizedDecimals(mixed $values, string $field): array
    {
        if (! is_array($values) || $values === []) {
            throw new InvalidArgumentException("The {$field} list must not be empty.");
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("The {$field} values must be decimal strings.");
            }

            $value = (string) $value;

            if (preg_match('/^\d+(?:\.(\d+))?$/', $value, $matches) !== 1) {
                throw new InvalidArgumentException("The {$field} values must be positive decimals.");
            }

            if (isset($matches[1]) && strlen($matches[1]) > Decimal::SCALE) {
                throw new InvalidArgumentException("The {$field} values may have at most ".Decimal::SCALE.' decimal places.');
            }

            $decimal = Decimal::of($value);

            if (! $decimal->isPositive()) {
                throw new InvalidArgumentException("The {$field} values must be positive decimals.");
            }

            $normalized[] = $decimal->toString();
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException("The {$field} values must be unique.");
        }

        return $normalized;
    }

    /** @return list<int> */
    private function normalizedLevels(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            throw new InvalidArgumentException('The level_counts list must not be empty.');
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('The level_counts values must be integers.');
            }

            if (preg_match('/^\d+$/', (string) $value) !== 1) {
                throw new InvalidArgumentException('The level_counts values must be integers.');
            }

            $level = (int) $value;

            if ($level < 1 || $level > 50) {
                throw new InvalidArgumentException('Level counts must be between 1 and 50.');
            }

            $normalized[] = $level;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException('The level_counts values must be unique.');
        }

        return $normalized;
    }

    private function runLabel(BacktestSweep $sweep, string $center, string $spacing, int $levels): string
    {
        return mb_strimwidth(
            "{$sweep->name} · center {$center} · spacing {$spacing} · levels {$levels}",
            0,
            160,
            '...',
        );
    }
}
