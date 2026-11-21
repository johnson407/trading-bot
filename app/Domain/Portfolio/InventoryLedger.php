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

        [$baseAsset, $quoteAsset] = array_pad(explode('/', $bot->symbol), 2, 'USDT');
        $feeCurrency = $fill->fee_currency ?: $quoteAsset;
        $feeInBase = $feeCurrency === $baseAsset;
        $feeInQuote = $feeCurrency === $quoteAsset;
        // Fees in a third asset (for example BNB) are valued at the fill price
        // for P&L purposes but are not deducted from the pair's quote balance.
        $quoteFee = $feeInQuote ? $fee : $fee->mul($price);

        if ($fill->side === OrderSide::Buy) {
            $cost = $price->mul($quantity);
            $received = $feeInBase ? $quantity->sub($fee)->max(Decimal::zero()) : $quantity;
            $newBase = $base->add($received);
            $newAverage = $newBase->isZero()
                ? Decimal::zero()
                : $base->mul($average)->add($cost)->div($newBase);
            $newQuote = $quote->sub($cost)->sub($feeInQuote ? $fee : Decimal::zero());
            $realized = $realized->sub($quoteFee);
            $baseDelta = $received;
            $quoteDelta = $cost->add($feeInQuote ? $fee : Decimal::zero())->negate();
        } else {
            $baseOut = $feeInBase ? $quantity->add($fee) : $quantity;

            if ($baseOut->greaterThan($base)) {
                throw NegativeInventoryException::forSale($base->toString(), $baseOut->toString(), $bot->id);
            }

            $proceeds = $price->mul($quantity);
            $newBase = $base->sub($baseOut);
            $newAverage = $newBase->isZero() ? Decimal::zero() : $average;
            $newQuote = $quote->add($proceeds)->sub($feeInQuote ? $fee : Decimal::zero());
            $realized = $realized->add($price->sub($average)->mul($quantity))->sub($quoteFee);
            $baseDelta = $baseOut->negate();
            $quoteDelta = $proceeds->sub($feeInQuote ? $fee : Decimal::zero());
        }

        $bot->forceFill([
            'base_inventory' => $newBase->toString(),
            'quote_inventory' => $newQuote->toString(),
            'average_entry_price' => $newAverage->toString(),
            'realized_pnl' => $realized->toString(),
            'fees_paid' => Decimal::of($bot->fees_paid)->add($quoteFee)->toString(),
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
