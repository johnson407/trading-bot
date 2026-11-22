<?php

namespace App\Domain\Exit;

enum ExitRule: string
{
    case StopLoss = 'stop_loss';
    case TakeProfit = 'take_profit';
    case RangeExit = 'range_exit';

    public function label(): string
    {
        return match ($this) {
            self::StopLoss => 'Stop-loss',
            self::TakeProfit => 'Take-profit',
            self::RangeExit => 'Range exit',
        };
    }

    public function riskEventType(): string
    {
        return match ($this) {
            self::StopLoss => 'risk.stop_loss_triggered',
            self::TakeProfit => 'risk.take_profit_triggered',
            self::RangeExit => 'risk.range_exit_triggered',
        };
    }
}
