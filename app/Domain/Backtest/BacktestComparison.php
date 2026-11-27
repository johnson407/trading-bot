<?php

namespace App\Domain\Backtest;

use App\Domain\Support\Decimal;
use App\Models\BacktestRun;
use App\Models\Bot;
use Throwable;

final class BacktestComparison
{
    public const CAVEAT = 'The backtest and paper periods differ: the backtest is historical and uses close-only fills and historical candles, while paper results use live market data from the bot creation time. Risk limits are the current limits rather than the backtest historical limits, and the bot may have been edited since creation. Treat the comparison as directional, not as a promise of equivalent results.';

    public function __construct(
        private readonly Bot $bot,
        private readonly BacktestRun $source,
        private readonly Decimal $unrealizedPnl,
        private readonly int $paperFillCount,
    ) {}

    public static function make(
        Bot $bot,
        BacktestRun $source,
        Decimal|string|int $unrealizedPnl,
        int $paperFillCount,
    ): self {
        return new self($bot, $source, Decimal::of($unrealizedPnl), max(0, $paperFillCount));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $metrics = is_array($this->source->metrics) ? $this->source->metrics : [];
        $snapshot = is_array($this->source->config_snapshot) ? $this->source->config_snapshot : [];
        $sourceBot = is_array($snapshot['bot'] ?? null) ? $snapshot['bot'] : [];
        $sourceCapital = $this->decimalString(
            $sourceBot['capital_allocation'] ?? $this->source->capital_allocation,
        );
        $paperCapital = $this->decimalString($this->bot->capital_allocation);

        $sourceNet = $this->metric($metrics, 'net_pnl');
        $sourceReturn = $this->metric($metrics, 'return_percent');
        if ($sourceCapital !== null && Decimal::of($sourceCapital)->isZero()) {
            $sourceReturn = null;
        }

        $sourceFees = $this->metric($metrics, 'fees');
        $sourceFills = $this->metricInt($metrics, 'fill_count');
        $sourceMaxDrawdown = $this->metric($metrics, 'max_drawdown');
        $sourceMaxDrawdownPercent = $this->metric($metrics, 'max_drawdown_percent');

        $realized = $this->decimalString($this->bot->realized_pnl) ?? '0';
        $paperNet = Decimal::of($realized)->add($this->unrealizedPnl);
        $paperReturn = $this->returnPercent($paperNet, $paperCapital);
        $paperFees = $this->decimalString($this->bot->fees_paid) ?? '0';

        return [
            'source' => [
                'id' => $this->source->id,
                'name' => $this->source->name,
                'symbol' => $this->source->symbol,
                'timeframe' => $this->source->timeframe,
                'status' => $this->source->status?->value,
                'start_at' => $this->source->start_at?->toIso8601String(),
                'end_at' => $this->source->end_at?->toIso8601String(),
                'capital_allocation' => $sourceCapital,
                'net_pnl' => $sourceNet,
                'return_percent' => $sourceReturn,
                'fees' => $sourceFees,
                'fill_count' => $sourceFills,
                'max_drawdown' => $sourceMaxDrawdown,
                'max_drawdown_percent' => $sourceMaxDrawdownPercent,
                'metrics_available' => $metrics !== [],
            ],
            'paper' => [
                'capital_allocation' => $paperCapital,
                'realized_pnl' => $realized,
                'unrealized_pnl' => $this->unrealizedPnl->toString(),
                'net_pnl' => $paperNet->toString(),
                'return_percent' => $paperReturn,
                'fees' => $paperFees,
                'fill_count' => $this->paperFillCount,
            ],
            'deltas' => [
                'net_pnl' => $this->delta($paperNet, $sourceNet),
                'return_percent_points' => $this->delta($paperReturn, $sourceReturn),
                'fees' => $this->delta($paperFees, $sourceFees),
                'fill_count' => $sourceFills === null ? null : $this->paperFillCount - $sourceFills,
            ],
            'modified' => $this->isModified($sourceCapital),
            'caveat' => self::CAVEAT,
        ];
    }

    private function returnPercent(Decimal $net, ?string $capital): ?string
    {
        if ($capital === null || Decimal::of($capital)->isZero()) {
            return null;
        }

        return $net->div(Decimal::of($capital))->mul(Decimal::of('100'))->toString();
    }

    private function delta(Decimal|string|null $paper, ?string $source): ?string
    {
        if ($paper === null || $source === null) {
            return null;
        }

        return Decimal::of($paper)->sub(Decimal::of($source))->toString();
    }

    private function isModified(?string $sourceCapital): bool
    {
        $snapshot = is_array($this->source->config_snapshot) ? $this->source->config_snapshot : [];
        $sourceBot = is_array($snapshot['bot'] ?? null) ? $snapshot['bot'] : [];

        $capital = $sourceBot['capital_allocation'] ?? $sourceCapital;
        if (! $this->decimalEquals($capital, $this->bot->capital_allocation)) {
            return true;
        }

        if (! $this->decimalEquals($sourceBot['fee_rate'] ?? $this->source->fee_rate, $this->bot->fee_rate)) {
            return true;
        }

        $sourceStrategy = is_array($snapshot['strategy'] ?? null) ? $snapshot['strategy'] : [];
        $sourceStrategyKey = is_string($sourceStrategy['key'] ?? null)
            ? $sourceStrategy['key']
            : $this->source->strategy_key;

        if ($sourceStrategyKey !== $this->bot->strategy_key) {
            return true;
        }

        $sourceConfiguration = is_array($sourceStrategy['configuration'] ?? null)
            ? $sourceStrategy['configuration']
            : null;
        $currentConfiguration = is_array($this->bot->strategyConfig?->configuration)
            ? $this->bot->strategyConfig->configuration
            : null;

        if (is_array($sourceConfiguration)) {
            $sourceConfiguration = $this->normalizeConfiguration($sourceConfiguration, $sourceStrategyKey);
            $currentConfiguration = $this->normalizeConfiguration($currentConfiguration, $this->bot->strategy_key);

            if ($this->canonical($sourceConfiguration) !== $this->canonical($currentConfiguration)) {
                return true;
            }
        }

        $sourceExit = $snapshot['exit'] ?? null;
        if (is_array($sourceExit)) {
            foreach (['stop_loss_percent', 'take_profit_percent', 'range_low', 'range_high'] as $key) {
                if (array_key_exists($key, $sourceExit)
                    && ! $this->decimalEquals($sourceExit[$key], $this->bot->{$key})) {
                    return true;
                }
            }
        }

        return false;
    }

    private function decimalEquals(mixed $left, mixed $right): bool
    {
        $left = $this->decimalString($left);
        $right = $this->decimalString($right);

        if ($left === null || $right === null) {
            return $left === $right;
        }

        return Decimal::of($left)->equals(Decimal::of($right));
    }

    private function decimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Decimal::of((string) $value)->toString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $metrics */
    private function metric(array $metrics, string $key): ?string
    {
        if (! array_key_exists($key, $metrics) || $metrics[$key] === null || $metrics[$key] === '') {
            return null;
        }

        return $this->decimalString($metrics[$key]);
    }

    /** @param array<string, mixed> $metrics */
    private function metricInt(array $metrics, string $key): ?int
    {
        if (! array_key_exists($key, $metrics) || $metrics[$key] === null || $metrics[$key] === '') {
            return null;
        }

        return is_numeric($metrics[$key]) ? (int) $metrics[$key] : null;
    }

    /**
     * Align snapshot aliases with the configuration shape persisted by the
     * bot creation request before comparing them.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, mixed>|null
     */
    private function normalizeConfiguration(?array $configuration, string $strategyKey): ?array
    {
        if ($configuration === null) {
            return null;
        }

        if (array_key_exists('level_count', $configuration)
            && ! array_key_exists('levels_per_side', $configuration)) {
            $configuration['levels_per_side'] = $configuration['level_count'];
        }

        unset($configuration['level_count']);

        if ($strategyKey === 'dynamic-grid') {
            unset($configuration['spacing_value']);
        }

        if ($strategyKey === 'martingale-grid'
            && ! array_key_exists('base_order_quantity', $configuration)
            && array_key_exists('order_quantity', $configuration)) {
            $configuration['base_order_quantity'] = $configuration['order_quantity'];
        }

        return $configuration;
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(fn (mixed $item) => $this->canonical($item), $value);
        }

        if (is_int($value) || is_float($value)) {
            return $this->canonical((string) $value);
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $this->decimalString($value) ?? $value;
        }

        return $value;
    }
}
