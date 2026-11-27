<?php

namespace App\Services\Backtest;

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;
use App\Models\BacktestRun;
use InvalidArgumentException;

class BacktestBotPrefill
{
    private const EXIT_FIELDS = [
        'stop_loss_percent',
        'take_profit_percent',
        'range_low',
        'range_high',
    ];

    public function __construct(
        private readonly StrategyRegistry $strategies,
    ) {}

    /**
     * Determine whether a backtest snapshot can produce a valid paper bot.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function supports(?array $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        $bot = $snapshot['bot'] ?? null;
        $strategy = $snapshot['strategy'] ?? null;

        if (! is_array($bot) || ! is_array($strategy)) {
            return false;
        }

        $strategyKey = $this->string($bot['strategy_key'] ?? null);
        $snapshotKey = $this->string($strategy['key'] ?? null);

        if ($strategyKey === null
            || $snapshotKey === null
            || $strategyKey !== $snapshotKey
            || ! $this->strategies->has($strategyKey)) {
            return false;
        }

        $exchange = $this->string($bot['exchange'] ?? null);
        $symbol = $this->string($bot['symbol'] ?? null);

        if ($exchange === null
            || $symbol === null
            || preg_match('/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/', $symbol) !== 1) {
            return false;
        }

        if (! $this->isPositive($bot['capital_allocation'] ?? null)
            || ! $this->isBetween($bot['fee_rate'] ?? null, '0', '0.1')) {
            return false;
        }

        $configuration = $strategy['configuration'] ?? null;

        if (! is_array($configuration)) {
            return false;
        }

        if (! $this->isPositive($configuration['center_price'] ?? null)
            || ! $this->isPositive($configuration['order_quantity'] ?? null)
            || $this->levelCount($configuration) === null) {
            return false;
        }

        $spacingType = $this->string($configuration['spacing_type'] ?? null);

        if ($spacingType === null || ! in_array($spacingType, ['arithmetic', 'geometric'], true)) {
            return false;
        }

        if ($strategyKey !== 'dynamic-grid'
            && ! $this->isPositive($configuration['spacing_value'] ?? null)) {
            return false;
        }

        if ($strategyKey === 'trailing-grid'
            && ! $this->isPositive($configuration['trailing_distance'] ?? null)) {
            return false;
        }

        if ($strategyKey === 'martingale-grid'
            && ! $this->supportsMartingale($configuration)) {
            return false;
        }

        if ($strategyKey === 'dynamic-grid'
            && ! $this->supportsDynamic($configuration)) {
            return false;
        }

        return $this->supportsExits($snapshot['exit'] ?? null);
    }

    /**
     * Build form values for the existing bot creation form.
     *
     * @return array<string, mixed>
     */
    public function fromRun(BacktestRun $run): array
    {
        if ($run->status !== BacktestStatus::Completed) {
            throw new InvalidArgumentException('Only completed backtests can create a bot.');
        }

        $snapshot = $run->config_snapshot;

        if (! $this->supports($snapshot)) {
            throw new InvalidArgumentException('The backtest snapshot cannot be used to create a bot.');
        }

        $bot = $snapshot['bot'];
        $strategy = $snapshot['strategy'];
        $configuration = $strategy['configuration'];
        $strategyKey = $strategy['key'];

        $data = [
            'source_backtest_run_id' => $run->id,
            'name' => $this->name($run->name),
            'exchange' => $bot['exchange'],
            'symbol' => $bot['symbol'],
            'strategy_key' => $strategyKey,
            'mode' => 'paper',
            'capital_allocation' => Decimal::of($bot['capital_allocation'])->toString(),
            'fee_rate' => Decimal::of($bot['fee_rate'])->toString(),
            'center_price' => Decimal::of($configuration['center_price'])->toString(),
            'level_count' => $this->levelCount($configuration),
            'spacing_type' => $configuration['spacing_type'],
            'spacing_value' => null,
            'order_quantity' => Decimal::of($configuration['order_quantity'])->toString(),
            'trailing_distance' => null,
            'size_multiplier' => null,
            'max_order_quantity' => null,
            'max_increases' => null,
            'min_spacing' => null,
            'max_spacing' => null,
            'volatility_multiplier' => null,
            'recalculation_interval_seconds' => null,
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'range_low' => null,
            'range_high' => null,
        ];

        $spacing = $configuration['spacing_value'] ?? null;

        if ($strategyKey !== 'dynamic-grid' || ($spacing !== null && $this->isPositive($spacing))) {
            $data['spacing_value'] = Decimal::of($spacing)->toString();
        }

        if ($strategyKey === 'trailing-grid') {
            $data['trailing_distance'] = Decimal::of($configuration['trailing_distance'])->toString();
        }

        if ($strategyKey === 'martingale-grid') {
            $data['size_multiplier'] = Decimal::of($configuration['size_multiplier'])->toString();
            $data['max_order_quantity'] = Decimal::of($configuration['max_order_quantity'])->toString();
            $data['max_increases'] = $this->integer($configuration['max_increases'], 0);
        }

        if ($strategyKey === 'dynamic-grid') {
            $data['min_spacing'] = Decimal::of($configuration['min_spacing'])->toString();
            $data['max_spacing'] = Decimal::of($configuration['max_spacing'])->toString();
            $data['volatility_multiplier'] = Decimal::of($configuration['volatility_multiplier'])->toString();
            $data['recalculation_interval_seconds'] = $this->integer($configuration['recalculation_interval_seconds'], 1);
        }

        $exits = $snapshot['exit'] ?? [];

        foreach (self::EXIT_FIELDS as $field) {
            if (($exits[$field] ?? null) !== null) {
                $data[$field] = Decimal::of($exits[$field])->toString();
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $configuration */
    private function supportsMartingale(array $configuration): bool
    {
        return $this->isPositive($configuration['base_order_quantity'] ?? null)
            && $this->isPositive($configuration['max_order_quantity'] ?? null)
            && $this->isGreaterThan($configuration['size_multiplier'] ?? null, '1')
            && $this->integer($configuration['max_increases'] ?? null, 0) !== null;
    }

    /** @param array<string, mixed> $configuration */
    private function supportsDynamic(array $configuration): bool
    {
        if (! $this->isPositive($configuration['min_spacing'] ?? null)
            || ! $this->isPositive($configuration['max_spacing'] ?? null)
            || ! $this->isPositive($configuration['volatility_multiplier'] ?? null)
            || $this->integer($configuration['recalculation_interval_seconds'] ?? null, 1) === null) {
            return false;
        }

        if (Decimal::of($configuration['max_spacing'])->lessThan($configuration['min_spacing'])) {
            return false;
        }

        $spacing = $configuration['spacing_value'] ?? null;

        return $spacing === null || $this->isNonNegative($spacing);
    }

    private function supportsExits(mixed $exits): bool
    {
        if ($exits === null) {
            return true;
        }

        if (! is_array($exits)) {
            return false;
        }

        foreach (self::EXIT_FIELDS as $field) {
            $value = $exits[$field] ?? null;

            if ($value !== null && ! $this->isPositive($value)) {
                return false;
            }
        }

        $rangeLow = $exits['range_low'] ?? null;
        $rangeHigh = $exits['range_high'] ?? null;

        return $rangeLow === null
            || $rangeHigh === null
            || Decimal::of($rangeHigh)->greaterThan($rangeLow);
    }

    /** @param array<string, mixed> $configuration */
    private function levelCount(array $configuration): ?int
    {
        return $this->integer(
            $configuration['level_count'] ?? $configuration['levels_per_side'] ?? null,
            1,
            50,
        );
    }

    private function name(string $runName): string
    {
        $base = trim($runName);
        $name = $base === '' ? 'Backtest paper' : $base.' paper';

        return mb_substr($name, 0, 120);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?Decimal
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            return Decimal::of($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isPositive(mixed $value): bool
    {
        return $this->decimal($value)?->isPositive() ?? false;
    }

    private function isNonNegative(mixed $value): bool
    {
        $decimal = $this->decimal($value);

        return $decimal !== null && ! $decimal->isNegative();
    }

    private function isBetween(mixed $value, string $min, string $max): bool
    {
        $decimal = $this->decimal($value);

        return $decimal !== null
            && $decimal->greaterThanOrEqual($min)
            && $decimal->lessThanOrEqual($max);
    }

    private function isGreaterThan(mixed $value, string $other): bool
    {
        return $this->decimal($value)?->greaterThan($other) ?? false;
    }

    private function integer(mixed $value, int $min, ?int $max = null): ?int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            return null;
        }

        if ($integer < $min || ($max !== null && $integer > $max)) {
            return null;
        }

        return $integer;
    }
}
