<?php

namespace Tests\Support;

use App\Domain\Backtest\Contracts\CandleProvider;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\Candle;
use DateTimeImmutable;

final class FakeCandleProvider implements CandleProvider
{
    /** @var list<Candle> */
    public array $candles = [];

    /** @var list<array{start: DateTimeImmutable, end: DateTimeImmutable}> */
    public array $requests = [];

    /** @param list<Candle> $candles */
    public function __construct(array $candles = [])
    {
        $this->candles = $candles;
    }

    public function fetch(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $this->requests[] = ['start' => $start, 'end' => $end];

        return array_values(array_filter(
            $this->candles,
            fn (Candle $candle) => $candle->openTime >= $start && $candle->openTime < $end,
        ));
    }
}
