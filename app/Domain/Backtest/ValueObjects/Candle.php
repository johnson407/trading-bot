<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Support\Decimal;
use App\Domain\Support\Number;
use DateTimeImmutable;
use DateTimeZone;

final readonly class Candle
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public Decimal $open,
        public Decimal $high,
        public Decimal $low,
        public Decimal $close,
        public Decimal $volume,
    ) {}

    public static function make(
        DateTimeImmutable|string|int $openTime,
        mixed $open,
        mixed $high,
        mixed $low,
        mixed $close,
        mixed $volume,
    ): self {
        return new self(
            self::normalizeTime($openTime),
            Decimal::of(Number::decimalString($open)),
            Decimal::of(Number::decimalString($high)),
            Decimal::of(Number::decimalString($low)),
            Decimal::of(Number::decimalString($close)),
            Decimal::of(Number::decimalString($volume)),
        );
    }

    public function closeTime(Timeframe $timeframe): DateTimeImmutable
    {
        return $this->openTime->modify("+{$timeframe->seconds()} seconds");
    }

    public function openTimeMilliseconds(): int
    {
        return $this->openTime->getTimestamp() * 1000;
    }

    /** @return array{open_time: string, open: string, high: string, low: string, close: string, volume: string} */
    public function toArray(): array
    {
        return [
            'open_time' => $this->openTime->format(DATE_ATOM),
            'open' => $this->open->toString(),
            'high' => $this->high->toString(),
            'low' => $this->low->toString(),
            'close' => $this->close->toString(),
            'volume' => $this->volume->toString(),
        ];
    }

    private static function normalizeTime(DateTimeImmutable|string|int $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }

        if (is_int($value)) {
            // CCXT reports candle open times in milliseconds.
            return (new DateTimeImmutable('@'.intdiv($value, 1000)))
                ->setTimezone(new DateTimeZone('UTC'));
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
