<?php

namespace App\Domain\Analytics;

use App\Domain\Support\Decimal;
use App\Models\EquitySnapshot;
use App\Models\User;

class EquityHistory
{
    /**
     * @return array{
     *     points: array<int, array{at: string, equity: string}>,
     *     metrics: array{period_return: ?string, peak_equity: string, max_drawdown: string}|null,
     *     latest: array{at: string, equity: string}|null,
     *     range_days: int,
     *     available_ranges: array<int, int>,
     *     has_history: bool,
     *     collecting: bool
     * }
     */
    public function forUser(User $user, int $days): array
    {
        $days = max(1, $days);

        $snapshots = $user->equitySnapshots()
            ->where('captured_at', '>=', now('UTC')->subDays($days))
            ->orderBy('captured_at')
            ->get(['captured_at', 'equity']);

        $points = $snapshots
            ->map(fn (EquitySnapshot $snapshot): array => [
                'at' => $snapshot->captured_at->toIso8601String(),
                'equity' => $snapshot->equity,
            ])
            ->all();

        $latest = $user->equitySnapshots()
            ->orderByDesc('captured_at')
            ->first(['captured_at', 'equity']);

        return [
            'points' => $this->downsample($points),
            'metrics' => $this->metrics($snapshots->all()),
            'latest' => $latest === null ? null : [
                'at' => $latest->captured_at->toIso8601String(),
                'equity' => $latest->equity,
            ],
            'range_days' => $days,
            'available_ranges' => array_map('intval', config('analytics.ranges', [7, 30, 90])),
            'has_history' => $points !== [],
            'collecting' => count($points) < 2,
        ];
    }

    /**
     * @param  array<int, array{at: string, equity: string}>  $points
     * @return array<int, array{at: string, equity: string}>
     */
    private function downsample(array $points): array
    {
        $count = count($points);
        $cap = max(2, (int) config('analytics.chart_points', 500));

        if ($count <= $cap) {
            return $points;
        }

        $step = ($count - 1) / ($cap - 1);
        $sampled = [];

        for ($index = 0; $index < $cap; $index++) {
            $sampled[] = $points[(int) round($index * $step)];
        }

        return $sampled;
    }

    /**
     * @param  array<int, EquitySnapshot>  $snapshots
     * @return array{period_return: ?string, peak_equity: string, max_drawdown: string}|null
     */
    private function metrics(array $snapshots): ?array
    {
        if (count($snapshots) < 2) {
            return null;
        }

        $start = Decimal::of($snapshots[0]->equity);
        $end = Decimal::of($snapshots[array_key_last($snapshots)]->equity);
        $periodReturn = $start->isPositive()
            ? $end->sub($start)->div($start)->mul('100')->round(2)->toFixed(2)
            : null;

        $peak = Decimal::of($snapshots[0]->equity);
        $peakEquity = $snapshots[0]->equity;
        $maxDrawdown = Decimal::zero();

        foreach ($snapshots as $snapshot) {
            $equity = Decimal::of($snapshot->equity);

            if ($equity->greaterThan($peak)) {
                $peak = $equity;
                $peakEquity = $snapshot->equity;
            }

            if (! $peak->isPositive()) {
                continue;
            }

            $drawdown = $peak->sub($equity)->div($peak)->mul('100');

            if ($drawdown->greaterThan($maxDrawdown)) {
                $maxDrawdown = $drawdown;
            }
        }

        return [
            'period_return' => $periodReturn,
            'peak_equity' => $peakEquity,
            'max_drawdown' => $maxDrawdown->round(2)->toFixed(2),
        ];
    }
}
