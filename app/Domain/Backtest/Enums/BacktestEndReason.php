<?php

namespace App\Domain\Backtest\Enums;

enum BacktestEndReason: string
{
    case RangeEnd = 'range_end';
    case RiskPause = 'risk_pause';
    case StrategyStop = 'strategy_stop';
}
