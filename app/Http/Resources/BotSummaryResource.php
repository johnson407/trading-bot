<?php

namespace App\Http\Resources;

use App\Models\Bot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Bot */
class BotSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_backtest_run_id' => $this->source_backtest_run_id,
            'name' => $this->name,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'strategy_key' => $this->strategy_key,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'desired_status' => $this->desired_status?->value,
            'capital_allocation' => $this->capital_allocation,
            'fee_rate' => $this->fee_rate,
            'base_inventory' => $this->base_inventory,
            'quote_inventory' => $this->quote_inventory,
            'average_entry_price' => $this->average_entry_price,
            'realized_pnl' => $this->realized_pnl,
            'unrealized_pnl' => $this->unrealized_pnl ?? null,
            'fees_paid' => $this->fees_paid,
            'stop_loss_percent' => $this->stop_loss_percent,
            'take_profit_percent' => $this->take_profit_percent,
            'range_low' => $this->range_low,
            'range_high' => $this->range_high,
            'open_orders_count' => (int) ($this->open_orders_count ?? 0),
            'active_grid' => $this->whenLoaded('activeGrid', fn () => $this->activeGrid === null ? null : [
                'id' => $this->activeGrid->id,
                'center_price' => $this->activeGrid->center_price,
                'level_count' => $this->activeGrid->level_count,
                'spacing_type' => $this->activeGrid->spacing_type->value,
                'status' => $this->activeGrid->status->value,
            ]),
            'last_tick_at' => $this->last_tick_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
