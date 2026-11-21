<?php

namespace App\Domain\Backtest\Contracts;

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\Candle;
use DateTimeImmutable;

interface CandleProvider
{
    /**
     * Fetch public OHLCV candles whose open time is within the requested range.
     * Implementations must return candles in ascending open-time order.
     *
     * @return list<Candle>
     */
    public function fetch(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array;
}
