<?php

namespace App\Domain\Backtest\Enums;

use InvalidArgumentException;

enum Timeframe: string
{
    case M1 = '1m';
    case M5 = '5m';
    case M15 = '15m';
    case H1 = '1h';
    case H4 = '4h';
    case D1 = '1d';

    public function seconds(): int
    {
        return match ($this) {
            self::M1 => 60,
            self::M5 => 300,
            self::M15 => 900,
            self::H1 => 3_600,
            self::H4 => 14_400,
            self::D1 => 86_400,
        };
    }

    public function label(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $timeframe) => $timeframe->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function configured(): array
    {
        $configured = config('backtest.timeframes', self::values());

        if (! is_array($configured)) {
            return self::values();
        }

        return array_values(array_filter(
            $configured,
            fn (mixed $value) => is_string($value) && self::tryFrom($value) !== null,
        ));
    }

    public static function fromConfig(string $value): self
    {
        $timeframe = self::tryFrom($value);

        if ($timeframe === null) {
            throw new InvalidArgumentException("Unsupported timeframe [{$value}].");
        }

        return $timeframe;
    }
}
