<?php

namespace App\Domain\Portfolio;

use App\Domain\Support\Decimal;
use App\Models\Bot;

/**
 * Resolves the most recent mark price known for a bot.
 *
 * The tick pipeline stores the latest public market price in bot settings. When
 * it is missing (for example before the first tick), the average entry price is
 * the closest usable reference.
 */
final class MarkPrice
{
    public static function for(Bot $bot): ?Decimal
    {
        $stored = $bot->settings['mark_price'] ?? null;

        if (is_numeric($stored) && Decimal::of((string) $stored)->isPositive()) {
            return Decimal::of((string) $stored);
        }

        if (Decimal::of($bot->average_entry_price)->isPositive()) {
            return Decimal::of($bot->average_entry_price);
        }

        return null;
    }
}
