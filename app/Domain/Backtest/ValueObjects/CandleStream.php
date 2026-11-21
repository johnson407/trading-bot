<?php

namespace App\Domain\Backtest\ValueObjects;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Lazy candle sequence backed by the market_candles table.
 *
 * The store validates coverage before constructing the stream, so the engine
 * can replay a large range without hydrating every candle at once.
 *
 * @implements IteratorAggregate<int, Candle>
 */
final class CandleStream implements Countable, IteratorAggregate
{
    /** @var callable(): Traversable<int, Candle> */
    private $factory;

    /**
     * @param  callable(): Traversable<int, Candle>  $factory
     */
    public function __construct(
        private readonly int $count,
        callable $factory,
    ) {
        $this->factory = $factory;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return Traversable<int, Candle> */
    public function getIterator(): Traversable
    {
        return ($this->factory)();
    }
}
