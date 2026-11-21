<?php

namespace App\Domain\Backtest\Engine;

use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;
use RuntimeException;

final class InMemoryPortfolio
{
    public Decimal $base;

    public Decimal $quote;

    public Decimal $averageEntry;

    public Decimal $realized;

    public Decimal $fees;

    public function __construct(string|Decimal $capital)
    {
        $this->base = Decimal::zero();
        $this->quote = Decimal::of($capital);
        $this->averageEntry = Decimal::zero();
        $this->realized = Decimal::zero();
        $this->fees = Decimal::zero();
    }

    /**
     * Apply a quote-currency fee fill with the same average-cost arithmetic as
     * InventoryLedger. Backtest fees are always charged in quote currency.
     */
    public function apply(SimulatedFill $fill): void
    {
        $price = $fill->price;
        $quantity = $fill->quantity;
        $fee = $fill->fee;

        if ($fill->side === OrderSide::Buy) {
            $cost = $price->mul($quantity);
            $newBase = $this->base->add($quantity);
            $this->averageEntry = $newBase->isZero()
                ? Decimal::zero()
                : $this->base->mul($this->averageEntry)->add($cost)->div($newBase);
            $this->base = $newBase;
            $this->quote = $this->quote->sub($cost)->sub($fee);
            $this->realized = $this->realized->sub($fee);
        } else {
            if ($quantity->greaterThan($this->base)) {
                throw new RuntimeException(
                    "Backtest cannot sell {$quantity->toString()} base units; only {$this->base->toString()} available.",
                );
            }

            $proceeds = $price->mul($quantity);
            $realized = $price->sub($this->averageEntry)->mul($quantity);
            $this->base = $this->base->sub($quantity);
            $this->averageEntry = $this->base->isZero() ? Decimal::zero() : $this->averageEntry;
            $this->quote = $this->quote->add($proceeds)->sub($fee);
            $this->realized = $this->realized->add($realized)->sub($fee);
        }

        $this->fees = $this->fees->add($fee);
    }

    public function equity(string|Decimal $markPrice): Decimal
    {
        return $this->quote->add($this->base->mul($markPrice));
    }

    public function unrealized(string|Decimal $markPrice): Decimal
    {
        if ($this->base->isZero()) {
            return Decimal::zero();
        }

        return Decimal::of($markPrice)->sub($this->averageEntry)->mul($this->base);
    }
}
