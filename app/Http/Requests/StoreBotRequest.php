<?php

namespace App\Http\Requests;

use App\Domain\Strategy\StrategyRegistry;
use App\Http\Requests\Concerns\BuildsStrategyConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBotRequest extends FormRequest
{
    use BuildsStrategyConfiguration;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Resolve the exchange once so an omitted or blank value cannot bypass the
     * allowlist through a different fallback downstream.
     */
    protected function prepareForValidation(): void
    {
        $exchange = trim((string) ($this->input('exchange') ?? ''));

        if ($exchange === '') {
            $exchange = (string) config('trading.default_exchange', 'binance');
        }

        $this->merge(['exchange' => $exchange]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $strategies = app(StrategyRegistry::class)->keys();

        return [
            'name' => ['required', 'string', 'max:120'],
            'exchange' => ['required', 'string', Rule::in(config('trading.exchanges', ['binance']))],
            'symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/'],
            'strategy_key' => ['required', 'string', Rule::in($strategies)],
            'mode' => ['nullable', Rule::in(['paper', 'live'])],
            'capital_allocation' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
            'center_price' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'level_count' => ['required', 'integer', 'min:1', 'max:50'],
            'spacing_type' => ['required', Rule::in(['arithmetic', 'geometric'])],
            'spacing_value' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'order_quantity' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'trailing_distance' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'size_multiplier' => ['nullable', 'numeric', 'gt:1'],
            'max_order_quantity' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_increases' => ['nullable', 'integer', 'min:0'],
            'min_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'volatility_multiplier' => ['nullable', 'numeric', 'gt:0'],
            'recalculation_interval_seconds' => ['nullable', 'integer', 'min:1'],
            'live_confirmation' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('mode', 'paper');

            if ($mode === 'live') {
                $exchange = (string) $this->input('exchange');
                $hasCredential = $this->user()
                    ->exchangeCredentials()
                    ->where('exchange', $exchange)
                    ->exists();

                if (! $hasCredential) {
                    $validator->errors()->add('mode', 'Live mode requires a saved exchange credential for this exchange.');
                }

                $expected = trim((string) $this->input('name')).' on '.$exchange;

                if (trim((string) $this->input('live_confirmation')) !== $expected) {
                    $validator->errors()->add(
                        'live_confirmation',
                        "Type \"{$expected}\" to confirm live trading.",
                    );
                }
            }

            $this->validateStrategyFields($validator, $this->input('strategy_key'));
        });
    }

    /** @return array<string, mixed> */
    public function strategyConfiguration(): array
    {
        return $this->buildStrategyConfiguration((string) $this->input('strategy_key'));
    }
}
