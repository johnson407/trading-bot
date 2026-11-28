<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Support\Decimal;
use Illuminate\Validation\Validator;

trait BuildsStrategyConfiguration
{
    /**
     * Enforce the strategy-specific fields that are required to build a valid
     * configuration. Shared by bot creation and bot updates so the two paths
     * cannot drift apart.
     */
    protected function validateStrategyFields(Validator $validator, ?string $strategy): void
    {
        if ($strategy === 'trailing-grid' && ! $this->filled('trailing_distance')) {
            $validator->errors()->add('trailing_distance', 'The trailing distance is required for trailing grids.');
        }

        if ($strategy === 'martingale-grid' && ! $this->filled('max_order_quantity')) {
            $validator->errors()->add('max_order_quantity', 'The maximum order quantity is required for martingale grids.');
        }

        if ($strategy === 'martingale-grid'
            && $this->filled('max_order_quantity')
            && $this->filled('order_quantity')
            && preg_match('/^\d+(\.\d+)?$/', (string) $this->input('max_order_quantity'))
            && preg_match('/^\d+(\.\d+)?$/', (string) $this->input('order_quantity'))
            && Decimal::of((string) $this->input('max_order_quantity'))->lessThan((string) $this->input('order_quantity'))) {
            $validator->errors()->add('max_order_quantity', 'The maximum order quantity must be at least the order quantity.');
        }

        if ($strategy === 'dynamic-grid') {
            if (! $this->filled('min_spacing')) {
                $validator->errors()->add('min_spacing', 'The minimum spacing is required for dynamic grids.');
            }

            if (! $this->filled('max_spacing')) {
                $validator->errors()->add('max_spacing', 'The maximum spacing is required for dynamic grids.');
            }

            if ($this->filled('min_spacing') && $this->filled('max_spacing')
                && Decimal::of((string) $this->input('max_spacing'))->lessThan((string) $this->input('min_spacing'))) {
                $validator->errors()->add('max_spacing', 'The maximum spacing must be at least the minimum spacing.');
            }
        }
    }

    /** @return array<string, mixed> */
    protected function buildStrategyConfiguration(string $strategy): array
    {
        $configuration = [
            'center_price' => Decimal::of((string) $this->input('center_price'))->toString(),
            'levels_per_side' => (int) $this->input('level_count'),
            // Dynamic grids ignore these two values; keep harmless defaults so
            // the stored configuration shape stays stable.
            'spacing_type' => (string) ($this->input('spacing_type') ?? 'arithmetic'),
            'spacing_value' => $this->filled('spacing_value')
                ? Decimal::of((string) $this->input('spacing_value'))->toString()
                : '0',
            'order_quantity' => Decimal::of((string) $this->input('order_quantity'))->toString(),
        ];

        if ($strategy === 'trailing-grid') {
            $configuration['trailing_distance'] = Decimal::of((string) $this->input('trailing_distance'))->toString();
        }

        if ($strategy === 'martingale-grid') {
            $configuration['base_order_quantity'] = $configuration['order_quantity'];
            $configuration['size_multiplier'] = Decimal::of((string) ($this->input('size_multiplier') ?? '2'))->toString();
            $configuration['max_order_quantity'] = Decimal::of((string) $this->input('max_order_quantity'))->toString();
            $configuration['max_increases'] = (int) ($this->input('max_increases') ?? 3);
        }

        if ($strategy === 'dynamic-grid') {
            $configuration['min_spacing'] = Decimal::of((string) $this->input('min_spacing'))->toString();
            $configuration['max_spacing'] = Decimal::of((string) $this->input('max_spacing'))->toString();
            $configuration['volatility_multiplier'] = Decimal::of((string) ($this->input('volatility_multiplier') ?? '1'))->toString();
            $configuration['recalculation_interval_seconds'] = (int) ($this->input('recalculation_interval_seconds') ?? 60);
            $configuration['spacing_hysteresis_percent'] = Decimal::of((string) ($this->input('spacing_hysteresis_percent') ?? '20'))->toString();
        }

        return $configuration;
    }
}
