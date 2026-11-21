<?php

namespace App\Services\Backtest;

use App\Domain\Backtest\Contracts\CandleProvider;
use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Backtest\ValueObjects\Candle;
use App\Domain\Backtest\ValueObjects\CandleStream;
use App\Models\MarketCandle;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CandleStore
{
    public function __construct(private readonly CandleProvider $provider) {}

    /**
     * Validate and fetch a contiguous range, then return a lazy stream.
     *
     * Missing spans are fetched in bounded windows so one span can never
     * exceed PostgreSQL's parameter limit or exhaust worker memory.
     */
    public function ensure(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?DateTimeImmutable $now = null,
    ): CandleStream {
        $start = $start->setTimezone(new DateTimeZone('UTC'));
        $end = $end->setTimezone(new DateTimeZone('UTC'));
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        $seconds = $timeframe->seconds();
        $firstOpen = $this->ceilToBoundary($start, $seconds);
        $boundedEnd = $end < $now ? $end : $now;
        $lastOpen = $this->floorToBoundary(
            $boundedEnd->modify("-{$seconds} seconds"),
            $seconds,
        );

        if ($lastOpen < $firstOpen) {
            return $this->emptyStream();
        }

        $expectedCount = intdiv(
            $lastOpen->getTimestamp() - $firstOpen->getTimestamp(),
            $seconds,
        ) + 1;

        $present = $this->presentTimestamps($exchange, $symbol, $timeframe, $firstOpen, $lastOpen);
        $spans = $this->missingSpans($firstOpen, $lastOpen, $seconds, $present);

        if ($spans !== []) {
            $this->fetchSpans($exchange, $symbol, $timeframe, $seconds, $spans, $firstOpen, $lastOpen, $now);
            $present = $this->presentTimestamps($exchange, $symbol, $timeframe, $firstOpen, $lastOpen);
        }

        if ($present === []) {
            return $this->emptyStream();
        }

        $missingAt = $this->firstMissing($firstOpen, $lastOpen, $seconds, $present);

        if ($missingAt instanceof DateTimeImmutable) {
            $missingCount = max(1, $expectedCount - count($present));

            throw new RuntimeException(
                'Historical data gap at '.$missingAt->format(DATE_ATOM)
                .' ('.$missingCount.' candle'.($missingCount === 1 ? '' : 's').' missing).',
            );
        }

        return new CandleStream(
            count: $expectedCount,
            factory: fn () => $this->stream($exchange, $symbol, $timeframe, $firstOpen, $lastOpen),
        );
    }

    /**
     * @return \Generator<int, Candle>
     */
    private function stream(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): \Generator {
        $rows = DB::table('market_candles')
            ->select(['open_time', 'open', 'high', 'low', 'close', 'volume'])
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe->value)
            ->whereBetween('open_time', [$start, $end])
            ->whereRaw('MOD(EXTRACT(EPOCH FROM open_time)::bigint, ?) = 0', [$timeframe->seconds()])
            ->orderBy('open_time')
            ->cursor();

        foreach ($rows as $row) {
            yield Candle::make(
                $row->open_time,
                $row->open,
                $row->high,
                $row->low,
                $row->close,
                $row->volume,
            );
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     */
    private function fetchSpans(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        int $seconds,
        array $spans,
        DateTimeImmutable $firstOpen,
        DateTimeImmutable $lastOpen,
        DateTimeImmutable $now,
    ): void {
        $window = max(1, (int) config('backtest.fetch_window', 5_000));

        foreach ($spans as [$spanStart, $spanEnd]) {
            $cursor = $spanStart;

            while ($cursor <= $spanEnd) {
                $windowEnd = min($spanEnd, $cursor + ($window - 1) * $seconds);
                $candles = $this->provider->fetch(
                    $exchange,
                    $symbol,
                    $timeframe,
                    $this->instant($cursor),
                    $this->instant($windowEnd + $seconds),
                );

                $rows = [];

                foreach ($candles as $candle) {
                    $open = $candle->openTime->getTimestamp();

                    if ($open < $firstOpen->getTimestamp() || $open > $lastOpen->getTimestamp()) {
                        continue;
                    }

                    if ($open % $seconds !== 0) {
                        continue;
                    }

                    if ($candle->closeTime($timeframe) > $now) {
                        continue;
                    }

                    $rows[] = [
                        'exchange' => $exchange,
                        'symbol' => $symbol,
                        'timeframe' => $timeframe->value,
                        'open_time' => $candle->openTime->format('Y-m-d H:i:s'),
                        'open' => $candle->open->toString(),
                        'high' => $candle->high->toString(),
                        'low' => $candle->low->toString(),
                        'close' => $candle->close->toString(),
                        'volume' => $candle->volume->toString(),
                        'created_at' => $now->format('Y-m-d H:i:s'),
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::transaction(fn () => MarketCandle::query()->upsert(
                        $chunk,
                        ['exchange', 'symbol', 'timeframe', 'open_time'],
                        ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
                    ));
                }

                $cursor = $windowEnd + $seconds;
            }
        }
    }

    /**
     * @return array<int, true>
     */
    private function presentTimestamps(
        string $exchange,
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $timestamps = DB::table('market_candles')
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe->value)
            ->whereBetween('open_time', [$start, $end])
            ->whereRaw('MOD(EXTRACT(EPOCH FROM open_time)::bigint, ?) = 0', [$timeframe->seconds()])
            ->selectRaw('EXTRACT(EPOCH FROM open_time)::bigint AS open_ts')
            ->pluck('open_ts');

        $present = [];

        foreach ($timestamps as $timestamp) {
            $present[(int) $timestamp] = true;
        }

        return $present;
    }

    /**
     * @param  array<int, true>  $present
     * @return list<array{0: int, 1: int}>
     */
    private function missingSpans(
        DateTimeImmutable $firstOpen,
        DateTimeImmutable $lastOpen,
        int $seconds,
        array $present,
    ): array {
        $spans = [];
        $spanStart = null;
        $previous = null;
        $cursor = $firstOpen->getTimestamp();
        $end = $lastOpen->getTimestamp();

        while ($cursor <= $end) {
            if (! isset($present[$cursor])) {
                if ($spanStart === null) {
                    $spanStart = $cursor;
                }

                $previous = $cursor;
            } elseif ($spanStart !== null) {
                $spans[] = [$spanStart, $previous ?? $spanStart];
                $spanStart = null;
                $previous = null;
            }

            $cursor += $seconds;
        }

        if ($spanStart !== null) {
            $spans[] = [$spanStart, $previous ?? $spanStart];
        }

        return $spans;
    }

    /**
     * @param  array<int, true>  $present
     */
    private function firstMissing(
        DateTimeImmutable $firstOpen,
        DateTimeImmutable $lastOpen,
        int $seconds,
        array $present,
    ): ?DateTimeImmutable {
        $cursor = $firstOpen->getTimestamp();
        $end = $lastOpen->getTimestamp();

        while ($cursor <= $end) {
            if (! isset($present[$cursor])) {
                return $this->instant($cursor);
            }

            $cursor += $seconds;
        }

        return null;
    }

    private function emptyStream(): CandleStream
    {
        return new CandleStream(0, static fn () => new \EmptyIterator);
    }

    private function ceilToBoundary(DateTimeImmutable $time, int $seconds): DateTimeImmutable
    {
        $timestamp = $time->getTimestamp();
        $remainder = $timestamp % $seconds;

        return $this->instant($remainder === 0 ? $timestamp : $timestamp + ($seconds - $remainder));
    }

    private function floorToBoundary(DateTimeImmutable $time, int $seconds): DateTimeImmutable
    {
        $timestamp = $time->getTimestamp();

        return $this->instant($timestamp - ($timestamp % $seconds));
    }

    private function instant(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
