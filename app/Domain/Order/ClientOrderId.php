<?php

namespace App\Domain\Order;

use App\Models\Bot;
use App\Models\GridLevel;

class ClientOrderId
{
    /**
     * Deterministic identifier for one bot/grid-level cycle. Re-requesting the
     * same level in the same cycle resolves to the same order.
     */
    public static function for(Bot $bot, GridLevel $level, int $cycle = 1): string
    {
        if ($cycle < 1) {
            throw new \InvalidArgumentException('Cycle must be at least 1.');
        }

        return sprintf(
            'bot-%d-grid-%d-level-%d-cycle-%d',
            $bot->id,
            $level->grid_id,
            $level->sequence,
            $cycle,
        );
    }
}
