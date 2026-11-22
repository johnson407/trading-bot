<?php

namespace App\Http\Requests;

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\GridPlanEvaluator;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Risk\RiskLimitService;
use App\Http\Requests\Concerns\BuildsStrategyConfiguration;
use App\Models\Bot;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBotRequest extends FormRequest
{
    use BuildsStrategyConfiguration;

    public function authorize(): bool
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot && $this->user()?->can('update', $bot) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'capital_allocation' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'fee_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:0.1'],
            'center_price' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'level_count' => ['required', 'integer', 'min:1', 'max:50'],
            'spacing_type' => [
                'nullable',
                Rule::requiredIf(fn () => $this->route('bot')?->strategy_key !== 'dynamic-grid'),
                Rule::in(['arithmetic', 'geometric']),
            ],
            'spacing_value' => [
                'nullable',
                Rule::requiredIf(fn () => $this->route('bot')?->strategy_key !== 'dynamic-grid'),
                'regex:/^\d+(\.\d+)?$/',
                'gt:0',
            ],
            'order_quantity' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'trailing_distance' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'size_multiplier' => ['nullable', 'numeric', 'gt:1'],
            'max_order_quantity' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_increases' => ['nullable', 'integer', 'min:0'],
            'min_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_spacing' => ['nullable', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'volatility_multiplier' => ['nullable', 'numeric', 'gt:0'],
            'recalculation_interval_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Bot|null $bot */
            $bot = $this->route('bot');

            $this->validateStrategyFields($validator, $bot?->strategy_key);

            if ($bot === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $plan = app(GridPlanEvaluator::class)->evaluate($this->gridPlanRequest($bot));

            foreach ($plan->errors() as $problem) {
                $validator->errors()->add($problem->field ?? 'strategy_key', $problem->message);
            }
        });
    }

    /** @return array<string, mixed> */
    public function strategyConfiguration(Bot $bot): array
    {
        return $this->buildStrategyConfiguration($bot->strategy_key);
    }

    public function gridPlanRequest(Bot $bot, ?MarketData $market = null): GridPlanRequest
    {
        $market ??= app(MarketDataResolver::class)->cached($bot->exchange, $bot->symbol);

        return new GridPlanRequest(
            strategyKey: $bot->strategy_key,
            configuration: $this->strategyConfiguration($bot),
            symbol: $bot->symbol,
            capitalAllocation: (string) $this->input('capital_allocation'),
            baseInventory: $bot->base_inventory,
            marketPrice: $market?->last,
            limits: app(RiskLimitService::class)->effectiveForBot($bot),
            market: $market,
        );
    }
}
