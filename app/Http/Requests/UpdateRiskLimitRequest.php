<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRiskLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'max_position_notional' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'max_open_orders' => ['required', 'integer', 'min:1', 'max:1000'],
            'daily_loss_limit' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
