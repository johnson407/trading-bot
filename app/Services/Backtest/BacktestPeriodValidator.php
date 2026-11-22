<?php

namespace App\Services\Backtest;

use App\Domain\Backtest\Enums\Timeframe;
use DateTimeImmutable;

class BacktestPeriodValidator
{
    /**
     * Validate a backtest period and return a field/message pair on failure.
     *
     * @return array{field: string, message: string}|null
     */
    public function validate(Timeframe $timeframe, DateTimeImmutable $startAt, DateTimeImmutable $endAt): ?array
    {
        if ($endAt <= $startAt) {
            return [
                'field' => 'end_at',
                'message' => 'The end date must be after the start date.',
            ];
        }

        $seconds = max(1, $timeframe->seconds());
        $estimated = (int) floor(($endAt->getTimestamp() - $startAt->getTimestamp()) / $seconds);
        $maximum = (int) config('backtest.max_candles', 200_000);

        if ($estimated > $maximum) {
            return [
                'field' => 'timeframe',
                'message' => "This range produces about {$estimated} candles; the limit is {$maximum}. Choose a shorter range or a larger timeframe.",
            ];
        }

        return null;
    }
}
