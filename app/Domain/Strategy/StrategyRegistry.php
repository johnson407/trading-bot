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
    /** @var list<string> */
    private const KEYS = [
        'simple-grid',
        'trailing-grid',
        'martingale-grid',
        'dynamic-grid',
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
