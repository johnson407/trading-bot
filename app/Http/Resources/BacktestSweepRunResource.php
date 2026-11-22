<?php

namespace App\Http\Resources;

use App\Models\BacktestRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BacktestRun */
class BacktestSweepRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $configuration = $this->config_snapshot['strategy']['configuration'] ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'candles_processed' => $this->candles_processed,
            'end_reason' => $this->end_reason?->value,
            'error' => $this->error,
            'swept' => [
                'center_price' => $configuration['center_price'] ?? null,
                'spacing_value' => $configuration['spacing_value'] ?? null,
                'level_count' => $configuration['level_count'] ?? $configuration['levels_per_side'] ?? null,
                'levels_per_side' => $configuration['levels_per_side'] ?? null,
            ],
            'metrics' => $this->metrics,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
