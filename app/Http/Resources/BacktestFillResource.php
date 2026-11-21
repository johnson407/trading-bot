<?php

namespace App\Http\Resources;

use App\Models\BacktestFill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BacktestFill */
class BacktestFillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'side' => $this->side->value,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'fee' => $this->fee,
            'fee_currency' => $this->fee_currency,
            'filled_at' => $this->filled_at?->toIso8601String(),
            'grid_level_sequence' => $this->grid_level_sequence,
        ];
    }
}
