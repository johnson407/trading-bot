<?php

namespace App\Http\Resources;

use App\Models\BacktestSweep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BacktestSweep */
class BacktestSweepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = max(0, (int) ($this->runs_count ?? $this->total_runs));
        $completed = (int) ($this->completed_runs_count ?? 0);
        $failed = (int) ($this->failed_runs_count ?? 0);
        $active = (int) ($this->active_runs_count ?? 0);
        $running = (int) ($this->running_runs_count ?? 0);
        $finished = $completed + $failed;

        $status = match (true) {
            $total === 0 => 'incomplete',
            $running > 0 => 'running',
            $active > 0 => 'pending',
            $failed > 0 => 'failed',
            $completed >= $total => 'completed',
            default => 'pending',
        };

        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'bot_name' => $this->bot?->name,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'parameters' => $this->parameters,
            'total_runs' => $this->total_runs,
            'runs_count' => (int) ($this->runs_count ?? $this->total_runs),
            'pending_runs_count' => (int) ($this->pending_runs_count ?? 0),
            'running_runs_count' => $running,
            'active_runs_count' => $active,
            'completed_runs_count' => $completed,
            'failed_runs_count' => $failed,
            'status' => $status,
            'progress' => $total === 0 ? 100 : (int) floor(min(100, $finished / $total * 100)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
