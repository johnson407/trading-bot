<?php

namespace App\Domain\Backtest\Enums;

enum BacktestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
