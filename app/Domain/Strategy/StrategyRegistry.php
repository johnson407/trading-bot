<?php

namespace App\Domain\Strategy;

/**
 * The engine's strategy catalog for form validation and backtest parameter
 * sweeps. Strategy evaluation itself lives in the Go engine
 * (engine/internal/strategy); this class deliberately has no PHP runtime
 * implementations so there is only one evaluator.
 */
class StrategyRegistry
{
    public const DYNAMIC_GRID = 'dynamic-grid';

    /** @var list<string> */
    private const KEYS = [
        'simple-grid',
        'trailing-grid',
        'martingale-grid',
        self::DYNAMIC_GRID,
    ];

    public function has(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return self::KEYS;
    }
}
