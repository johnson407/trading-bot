<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\CanCreateBacktestBot;
use App\Models\BacktestRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BacktestRun */
class BacktestRunResource extends JsonResource
{
    use CanCreateBacktestBot;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'can_create_bot' => $this->canCreateBot($request),
            'bot_id' => $this->bot_id,
            'name' => $this->name,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'strategy_key' => $this->strategy_key,
            'timeframe' => $this->timeframe,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'capital_allocation' => $this->capital_allocation,
            'fee_rate' => $this->fee_rate,
            'status' => $this->status->value,
            'end_reason' => $this->end_reason?->value,
            'candles_processed' => $this->candles_processed,
            'progress' => $this->progress,
            'metrics' => $this->metrics,
            'equity_curve' => $this->equity_curve ?? [],
            'error' => $this->error,
            'config_snapshot' => $this->config_snapshot,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
