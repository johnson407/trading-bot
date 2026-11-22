<?php

/**
 * Quick-setup presets.
 *
 * Presets are symbol-agnostic: spacing is a percentage of the center price and
 * order size is derived from the capital allocation, so the same preset works
 * for BTC/USDT and ETH/USDT. Levels per side is capped at 10 so the resulting
 * order count (2 x levels) stays within the default 20-open-order limit.
 *
 * `spacing_percent` is expressed in percent (`0.4` means 0.4%) and maps to
 * geometric spacing as a fraction (`0.004`).
 */
return [
    'balanced' => [
        'label' => 'Balanced grid',
        'description' => 'Evenly spaced levels around the current price. A good default for ranging markets.',
        'strategy' => 'simple-grid',
        'levels_per_side' => 6,
        'spacing_type' => 'geometric',
        'spacing_percent' => '0.4',
        'utilization' => '0.8',
        'strategy_defaults' => [],
    ],

    'tight' => [
        'label' => 'Tight grid',
        'description' => 'More levels close together for small moves. Uses the full default order limit.',
        'strategy' => 'simple-grid',
        'levels_per_side' => 10,
        'spacing_type' => 'geometric',
        'spacing_percent' => '0.2',
        'utilization' => '0.8',
        'strategy_defaults' => [],
    ],

    'wide' => [
        'label' => 'Wide grid',
        'description' => 'Fewer, wider levels for larger price swings.',
        'strategy' => 'simple-grid',
        'levels_per_side' => 4,
        'spacing_type' => 'geometric',
        'spacing_percent' => '1.0',
        'utilization' => '0.8',
        'strategy_defaults' => [],
    ],

    'trailing' => [
        'label' => 'Trailing grid',
        'description' => 'Re-centers the ladder when the price moves beyond the trailing distance.',
        'strategy' => 'trailing-grid',
        'levels_per_side' => 6,
        'spacing_type' => 'geometric',
        'spacing_percent' => '0.5',
        'utilization' => '0.8',
        'strategy_defaults' => [
            'trailing_distance_percent' => '1.5',
        ],
    ],
];
