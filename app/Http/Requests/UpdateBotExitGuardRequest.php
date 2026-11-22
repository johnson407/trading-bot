<?php

namespace App\Http\Requests;

use App\Domain\Support\Decimal;
use App\Models\Bot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

class UpdateBotExitGuardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot && $this->user()?->can('control', $bot) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'stop_loss_percent' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'decimal:0,4', 'gt:0', 'lt:100'],
            'take_profit_percent' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'decimal:0,4', 'gt:0', 'lt:100'],
            'range_low' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'decimal:0,8', 'gt:0', 'max:99999999.99999999'],
            'range_high' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'decimal:0,8', 'gt:0', 'max:99999999.99999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $bot = $this->route('bot');
            $low = $this->effectiveBound('range_low', $bot instanceof Bot ? $bot : null);
            $high = $this->effectiveBound('range_high', $bot instanceof Bot ? $bot : null);

            if ($low === null || $high === null) {
                return;
            }

            if ($high->lessThanOrEqual($low)) {
                $validator->errors()->add('range_high', 'The range high must be greater than the range low.');
            }
        });
    }

    private function effectiveBound(string $field, ?Bot $bot): ?Decimal
    {
        if ($this->has($field)) {
            if (! $this->filled($field)) {
                return null;
            }

            try {
                return Decimal::of((string) $this->input($field));
            } catch (Throwable) {
                return null;
            }
        }

        if ($bot?->{$field} === null) {
            return null;
        }

        try {
            return Decimal::of((string) $bot->{$field});
        } catch (Throwable) {
            return null;
        }
    }
}
