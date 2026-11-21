<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Support\Decimal;

final readonly class BacktestRiskLimits
{
    public function __construct(
        public Decimal $maxPositionNotional,
        public int $maxOpenOrders,
        public Decimal $dailyLossLimit,
    ) {}

    public static function make(
        string|Decimal $maxPositionNotional,
        int $maxOpenOrders,
        string|Decimal $dailyLossLimit,
    ): self {
        return new self(
            Decimal::of($maxPositionNotional),
            $maxOpenOrders,
            Decimal::of($dailyLossLimit),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return self::make(
            $data['max_position_notional'] ?? '1000',
            (int) ($data['max_open_orders'] ?? 20),
            $data['daily_loss_limit'] ?? '100',
        );
    }

    /** @return array{max_position_notional: string, max_open_orders: int, daily_loss_limit: string} */
    public function toArray(): array
    {
        return [
            'max_position_notional' => $this->maxPositionNotional->toString(),
            'max_open_orders' => $this->maxOpenOrders,
            'daily_loss_limit' => $this->dailyLossLimit->toString(),
        ];
    }
}
