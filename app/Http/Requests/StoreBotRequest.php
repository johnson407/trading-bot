<?php

namespace App\Http\Requests;

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Exceptions\GridPlanUnavailableException;
use App\Domain\Grid\PresetResolver;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Risk\RiskLimitService;
use App\Domain\Strategy\StrategyRegistry;
use App\Http\Requests\Concerns\BuildsStrategyConfiguration;
use App\Services\Engine\GridPlanClient;
use App\Services\Exchange\MarketDataResolver;
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
        // Quick setup trusts the selected preset, not the derived fields that
        // happen to be in the browser when the form is submitted. Deriving here
        // closes the race where a stale preview would otherwise be saved.
        if ($this->filled('preset_key') && $this->filled('center_price') && $this->filled('capital_allocation')) {
            try {
                $this->merge(app(PresetResolver::class)->values(
                    (string) $this->input('preset_key'),
                    (string) $this->input('center_price'),
                    (string) $this->input('capital_allocation'),
                ));
            } catch (\Throwable) {
                // Let normal validation explain the invalid preset or values.
            }
        }

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
            'preset_key' => ['nullable', 'string', Rule::in(app(PresetResolver::class)->keys())],
            'exchange' => ['required', 'string', Rule::in(config('trading.exchanges', ['binance']))],
            'symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/'],
            'strategy_key' => ['required', 'string', Rule::in($strategies)],
            'mode' => ['nullable', Rule::in(['paper', 'live'])],
            'capital_allocation' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
            'center_price' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'level_count' => ['required', 'integer', 'min:1', 'max:50'],
            // Dynamic grids choose their own spacing, so the shared geometry
            // fields are not required for them and are hidden in the form.
            'spacing_type' => ['nullable', 'required_unless:strategy_key,dynamic-grid', Rule::in(['arithmetic', 'geometric'])],
            'spacing_value' => ['nullable', 'required_unless:strategy_key,dynamic-grid', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'order_quantity' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'trailing_distance' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'size_multiplier' => ['nullable', 'numeric', 'gt:1'],
            'max_order_quantity' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_increases' => ['nullable', 'integer', 'min:0'],
            'min_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'volatility_multiplier' => ['nullable', 'numeric', 'gt:0'],
            'recalculation_interval_seconds' => ['nullable', 'integer', 'min:1'],
            'spacing_hysteresis_percent' => ['nullable', 'numeric', 'regex:/^\d+(\.\d+)?$/', 'min:0', 'max:90'],
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

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $plan = app(GridPlanClient::class)->evaluate($this->gridPlanRequest());
            } catch (GridPlanUnavailableException $exception) {
                $validator->errors()->add('strategy_key', $exception->getMessage());

                return;
            }

            foreach ($plan->errors() as $problem) {
                $validator->errors()->add($problem->field ?? 'strategy_key', $problem->message);
            }
        });
    }

    public function gridPlanRequest(?MarketData $market = null): GridPlanRequest
    {
        $symbol = (string) $this->input('symbol');
        $exchange = (string) $this->input('exchange');

        $market ??= app(MarketDataResolver::class)->cached($exchange, $symbol);

        return new GridPlanRequest(
            strategyKey: (string) $this->input('strategy_key'),
            configuration: $this->strategyConfiguration(),
            symbol: $symbol,
            capitalAllocation: (string) $this->input('capital_allocation'),
            marketPrice: $market?->last,
            limits: $this->user() !== null
                ? app(RiskLimitService::class)->effectiveForUser($this->user())
                : null,
            market: $market,
        );
    }

    /** @return array<string, mixed> */
    public function strategyConfiguration(): array
    {
        return $this->buildStrategyConfiguration((string) $this->input('strategy_key'));
    }
}
