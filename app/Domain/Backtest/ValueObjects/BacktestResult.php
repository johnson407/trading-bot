<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Strategy\ValueObjects\StrategyState;

final readonly class BacktestResult
{
    /**
     * @param  list<SimulatedFill>  $fills
     * @param  list<EquityPoint>  $equityCurve
     */
    public function __construct(
        public BacktestMetrics $metrics,
        public array $fills,
        public array $equityCurve,
        public StrategyState $finalState,
    ) {}
}
