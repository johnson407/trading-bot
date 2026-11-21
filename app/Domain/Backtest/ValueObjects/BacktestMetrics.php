<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Support\Decimal;

final readonly class BacktestMetrics
{
    public function __construct(
        public string $endReason,
        public Decimal $finalEquity,
        public Decimal $netPnl,
        public Decimal $realizedPnl,
        public Decimal $unrealizedPnl,
        public Decimal $fees,
        public Decimal $returnPercent,
        public Decimal $maxDrawdown,
        public Decimal $maxDrawdownPercent,
        public Decimal $finalBaseInventory,
        public Decimal $finalQuoteInventory,
        public Decimal $finalPrice,
        public int $fillCount,
        public int $buyCount,
        public int $sellCount,
        public int $candlesProcessed,
        public int $durationMs = 0,
    ) {}

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'end_reason' => $this->endReason,
            'final_equity' => $this->finalEquity->toString(),
            'net_pnl' => $this->netPnl->toString(),
            'realized_pnl' => $this->realizedPnl->toString(),
            'unrealized_pnl' => $this->unrealizedPnl->toString(),
            'fees' => $this->fees->toString(),
            'return_percent' => $this->returnPercent->toString(),
            'max_drawdown' => $this->maxDrawdown->toString(),
            'max_drawdown_percent' => $this->maxDrawdownPercent->toString(),
            'final_base_inventory' => $this->finalBaseInventory->toString(),
            'final_quote_inventory' => $this->finalQuoteInventory->toString(),
            'final_price' => $this->finalPrice->toString(),
            'fill_count' => $this->fillCount,
            'buy_count' => $this->buyCount,
            'sell_count' => $this->sellCount,
            'candles_processed' => $this->candlesProcessed,
            'duration_ms' => $this->durationMs,
        ];
    }
}
