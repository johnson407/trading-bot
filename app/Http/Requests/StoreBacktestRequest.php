<?php

namespace App\Http\Requests;

use App\Domain\Backtest\Enums\Timeframe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBacktestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bot_id' => ['required', 'integer', 'exists:bots,id'],
            'timeframe' => ['required', 'string', Rule::in(Timeframe::configured())],
            'start_at' => ['required', 'date', 'before_or_equal:end_at', 'before_or_equal:now'],
            'end_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:start_at'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
