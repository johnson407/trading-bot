<?php

namespace App\Domain\Strategy\ValueObjects;

use App\Domain\Support\Decimal;

final readonly class BotSnapshot
{
    /** @param list<OpenOrderSnapshot> $openOrders */
    public function __construct(
        public int $botId,
        public Decimal $baseInventory,
        public Decimal $quoteInventory,
        public Decimal $averageEntryPrice,
        public Decimal $realizedPnl,
        public Decimal $capitalAllocation,
        public array $openOrders = [],
    ) {
    }

    /** @param list<OpenOrderSnapshot> $openOrders */
    public static function make(
        int $botId,
        string|Decimal $baseInventory,
        string|Decimal $quoteInventory,
        string|Decimal $averageEntryPrice,
        string|Decimal $realizedPnl,
        string|Decimal $capitalAllocation,
        array $openOrders = [],
    ): self {
        return new self(
            botId: $botId,
            baseInventory: Decimal::of($baseInventory),
            quoteInventory: Decimal::of($quoteInventory),
            averageEntryPrice: Decimal::of($averageEntryPrice),
            realizedPnl: Decimal::of($realizedPnl),
            capitalAllocation: Decimal::of($capitalAllocation),
            openOrders: $openOrders,
        );
    }

    public function hasOpenOrderAt(string $price): bool
    {
        foreach ($this->openOrders as $order) {
            if ($order->price->equals($price)) {
                return true;
            }
        }

        return false;
    }

    public function openOrderForSequence(int $sequence): ?OpenOrderSnapshot
    {
        foreach ($this->openOrders as $order) {
            if ($order->gridLevelSequence === $sequence) {
                return $order;
            }
        }

        return null;
    }

    /** @param list<OpenOrderSnapshot> $openOrders */
    public function withOpenOrders(array $openOrders): self
    {
        return new self(
            $this->botId,
            $this->baseInventory,
            $this->quoteInventory,
            $this->averageEntryPrice,
            $this->realizedPnl,
            $this->capitalAllocation,
            $openOrders,
        );
    }
}
