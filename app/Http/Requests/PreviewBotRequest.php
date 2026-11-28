<?php

namespace App\Http\Requests;

use App\Domain\Grid\PresetResolver;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Risk\RiskLimitService;
use App\Domain\Strategy\StrategyRegistry;
use App\Http\Requests\Concerns\BuildsStrategyConfiguration;
use App\Http\Requests\Concerns\PreparesSourceBacktestBot;
use App\Models\Bot;
use App\Services\Exchange\MarketDataResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class PreviewBotRequest extends FormRequest
{
    use BuildsStrategyConfiguration;
    use PreparesSourceBacktestBot;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Resolve a quick-setup preset before validation so the same explicit
     * field rules apply to derived values as to hand-entered ones.
     */
    protected function prepareForValidation(): void
    {
        if ($this->prepareSourceBacktestBot(preview: true)) {
            return;
        }

        $exchange = trim((string) ($this->input('exchange') ?? ''));

        if ($exchange === '') {
            $exchange = (string) config('trading.default_exchange', 'binance');
        }

        $this->merge(['exchange' => $exchange]);

        $presetKey = trim((string) ($this->input('preset_key') ?? ''));
        $center = (string) ($this->input('center_price') ?? '');
        $capital = (string) ($this->input('capital_allocation') ?? '');

        if ($presetKey === '' || $center === '' || $capital === '') {
            return;
        }

        try {
            $values = app(PresetResolver::class)->values($presetKey, $center, $capital);
        } catch (Throwable) {
            return;
        }

        $this->merge($values);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $strategies = app(StrategyRegistry::class)->keys();

        return [
            'preset_key' => ['nullable', 'string', Rule::in(app(PresetResolver::class)->keys())],
            'source_backtest_run_id' => ['nullable', 'integer', 'min:1'],
            'bot_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
            'tick_interval_seconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'exchange' => ['required', 'string', Rule::in(config('trading.exchanges', ['binance']))],
            'symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/'],
            'strategy_key' => ['required_without:preset_key', 'string', Rule::in($strategies)],
            'capital_allocation' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'center_price' => ['required', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'level_count' => ['required_without:preset_key', 'integer', 'min:1', 'max:50'],
            'spacing_type' => [
                'nullable',
                Rule::requiredIf(fn () => ! $this->filled('preset_key') && $this->input('strategy_key') !== 'dynamic-grid'),
                Rule::in(['arithmetic', 'geometric']),
            ],
            'spacing_value' => [
                'nullable',
                Rule::requiredIf(fn () => ! $this->filled('preset_key') && $this->input('strategy_key') !== 'dynamic-grid'),
                'regex:/^\d+(\.\d+)?$/',
                'gt:0',
            ],
            'order_quantity' => ['required_without:preset_key', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'trailing_distance' => ['nullable', 'required_if:strategy_key,trailing-grid', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'size_multiplier' => ['nullable', 'numeric', 'gt:1'],
            'max_order_quantity' => ['nullable', 'required_if:strategy_key,martingale-grid', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_increases' => ['nullable', 'integer', 'min:0'],
            'min_spacing' => ['nullable', 'required_if:strategy_key,dynamic-grid', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'max_spacing' => ['nullable', 'required_if:strategy_key,dynamic-grid', 'regex:/^\d+(\.\d+)?$/', 'gt:0'],
            'volatility_multiplier' => ['nullable', 'numeric', 'gt:0'],
            'recalculation_interval_seconds' => ['nullable', 'integer', 'min:1'],
            'spacing_hysteresis_percent' => ['nullable', 'numeric', 'regex:/^\d+(\.\d+)?$/', 'min:0', 'max:90'],
        ];
    }

    /** @return array<string, mixed> */
    public function strategyConfiguration(): array
    {
        return $this->buildStrategyConfiguration((string) $this->input('strategy_key'));
    }

    public function previewBot(): ?Bot
    {
        if (! $this->filled('bot_id')) {
            return null;
        }

        return Bot::query()
            ->where('user_id', $this->user()?->id)
            ->find($this->integer('bot_id'));
    }

    /**
     * Shape the submitted edit the same way BotEditDiff expects, falling back
     * to the current bot values for fields the preview payload omits.
     *
     * @return array<string, mixed>|null
     */
    public function proposedEdit(): ?array
    {
        $bot = $this->previewBot();

        if ($bot === null) {
            return null;
        }

        $strategyKey = (string) ($this->input('strategy_key') ?: $bot->strategy_key);

        return [
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : $bot->name,
            'capital_allocation' => (string) $this->input('capital_allocation'),
            'fee_rate' => $this->filled('fee_rate') ? (string) $this->input('fee_rate') : (string) $bot->fee_rate,
            'tick_interval_seconds' => $this->has('tick_interval_seconds')
                ? (int) $this->input('tick_interval_seconds')
                : (int) $bot->tick_interval_seconds,
            'configuration' => $this->buildStrategyConfiguration($strategyKey),
        ];
    }

    /** @return array<string, mixed> */
    public function effectiveLimits(): array
    {
        $bot = $this->previewBot();
        $service = app(RiskLimitService::class);

        return $bot !== null
            ? $service->effectiveForBot($bot)
            : $service->effectiveForUser($this->user());
    }

    public function gridPlanRequest(): GridPlanRequest
    {
        $bot = $this->previewBot();
        $strategyKey = $bot instanceof Bot ? $bot->strategy_key : (string) $this->input('strategy_key');
        $exchange = $bot instanceof Bot ? $bot->exchange : (string) $this->input('exchange');
        $symbol = $bot instanceof Bot ? $bot->symbol : (string) $this->input('symbol');
        $market = app(MarketDataResolver::class)->resolve($exchange, $symbol);

        return new GridPlanRequest(
            strategyKey: $strategyKey,
            configuration: $this->buildStrategyConfiguration($strategyKey),
            symbol: $symbol,
            capitalAllocation: (string) $this->input('capital_allocation'),
            baseInventory: $bot instanceof Bot ? $bot->base_inventory : '0',
            marketPrice: $market?->last,
            limits: $this->effectiveLimits(),
            market: $market,
        );
    }

    /** @return array<string, mixed> */
    public function concreteValues(): array
    {
        $values = [
            'strategy_key' => (string) $this->input('strategy_key'),
            'level_count' => (int) $this->input('level_count'),
            'spacing_type' => (string) $this->input('spacing_type'),
            'spacing_value' => (string) $this->input('spacing_value'),
            'order_quantity' => (string) $this->input('order_quantity'),
        ];

        foreach ([
            'trailing_distance',
            'size_multiplier',
            'max_order_quantity',
            'max_increases',
            'min_spacing',
            'max_spacing',
            'volatility_multiplier',
            'recalculation_interval_seconds',
            'spacing_hysteresis_percent',
        ] as $field) {
            if ($this->has($field)) {
                $values[$field] = $this->input($field);
            }
        }

        return $values;
    }
}
