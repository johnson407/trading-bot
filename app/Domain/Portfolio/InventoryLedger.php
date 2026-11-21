<?php

namespace App\Domain\Portfolio;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Portfolio\Exceptions\NegativeInventoryException;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\InventoryLedgerEntry;

class InventoryLedger
{
    /**
     * Apply one fill to the bot's average-cost inventory and P&L.
     * The caller is responsible for locking the bot row.
     */
    public function apply(Bot $bot, Fill $fill): InventoryLedgerEntry
    {
        $existing = InventoryLedgerEntry::query()->where('fill_id', $fill->id)->first();

        if ($existing instanceof InventoryLedgerEntry) {
            return $existing;
        }

        $price = Decimal::of($fill->price);
        $quantity = Decimal::of($fill->quantity);
        $fee = Decimal::of($fill->fee);
        $base = Decimal::of($bot->base_inventory);
        $quote = Decimal::of($bot->quote_inventory);
        $average = Decimal::of($bot->average_entry_price);
        $realized = Decimal::of($bot->realized_pnl);

        if ($fill->side === OrderSide::Buy) {
            $cost = $price->mul($quantity);
            $newBase = $base->add($quantity);
            $newAverage = $newBase->isZero()
                ? Decimal::zero()
                : $base->mul($average)->add($cost)->div($newBase);
            $newQuote = $quote->sub($cost)->sub($fee);
            $realized = $realized->sub($fee);
            $baseDelta = $quantity;
            $quoteDelta = $cost->add($fee)->negate();
        } else {
            if ($quantity->greaterThan($base)) {
                throw NegativeInventoryException::forSale($base->toString(), $quantity->toString(), $bot->id);
            }

            $proceeds = $price->mul($quantity);
            $newBase = $base->sub($quantity);
            $newAverage = $newBase->isZero() ? Decimal::zero() : $average;
            $newQuote = $quote->add($proceeds)->sub($fee);
            $realized = $realized->add($price->sub($average)->mul($quantity))->sub($fee);
            $baseDelta = $quantity->negate();
            $quoteDelta = $proceeds->sub($fee);
        }

        $bot->forceFill([
            'base_inventory' => $newBase->toString(),
            'quote_inventory' => $newQuote->toString(),
            'average_entry_price' => $newAverage->toString(),
            'realized_pnl' => $realized->toString(),
            'fees_paid' => Decimal::of($bot->fees_paid)->add($fee)->toString(),
        ])->save();

        return InventoryLedgerEntry::create([
            'bot_id' => $bot->id,
            'fill_id' => $fill->id,
            'side' => $fill->side,
            'quantity' => $quantity->toString(),
            'price' => $price->toString(),
            'base_delta' => $baseDelta->toString(),
            'quote_delta' => $quoteDelta->toString(),
            'base_balance' => $newBase->toString(),
            'quote_balance' => $newQuote->toString(),
            'average_entry_price' => $newAverage->toString(),
            'realized_pnl' => $realized->toString(),
            'created_at' => now(),
        ]);
    }

    public function unrealizedPnl(Bot $bot, Decimal|string $markPrice): Decimal
    {
        $base = Decimal::of($bot->base_inventory);

        if ($base->isZero()) {
            return Decimal::zero();
        }

        return Decimal::of($markPrice)->sub($bot->average_entry_price)->mul($base);
    }
}
