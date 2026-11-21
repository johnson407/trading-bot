<?php

namespace App\Domain\Exchange;

use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Support\Decimal;
use App\Models\Bot;

final class FeeResolver
{
    /**
     * Resolve the fee for one fill. Exchanges report a cumulative fee for the
     * order, so only the part not already booked is recorded. A missing fee
     * falls back to the bot's configured rate; an explicit zero stays zero and
     * a negative fee is a rebate.
     *
     * @return array{0: Decimal, 1: string}
     */
    public static function forFill(
        Bot $bot,
        ExchangeOrder $remote,
        Decimal $price,
        Decimal $quantity,
        ?Decimal $recorded = null,
    ): array {
        $currency = self::quoteCurrency($bot);

        if ($remote->fee !== null) {
            // Exchanges commonly report the cumulative order fee. Record only
            // the part that has not already been booked for earlier fills.
            $recorded ??= Decimal::zero();

            return [$remote->fee->sub($recorded), $remote->feeCurrency !== '' ? $remote->feeCurrency : $currency];
        }

        $rate = Decimal::of($bot->fee_rate ?? '0');

        if (! $rate->isPositive()) {
            return [Decimal::zero(), $currency];
        }

        return [$price->mul($quantity)->mul($rate), $currency];
    }

    public static function quoteCurrency(Bot $bot): string
    {
        return explode('/', $bot->symbol)[1] ?? 'USDT';
    }
}
