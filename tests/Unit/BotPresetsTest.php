<?php

use App\Domain\Grid\GridPlanEvaluator;
use App\Domain\Grid\PresetResolver;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;

it('ships a preset catalog with the required keys', function () {
    $presets = config('bot_presets');

    expect($presets)->toBeArray()->not->toBeEmpty();

    foreach ($presets as $preset) {
        expect($preset)->toHaveKeys([
            'label',
            'description',
            'strategy',
            'levels_per_side',
            'spacing_type',
            'spacing_percent',
            'utilization',
            'strategy_defaults',
        ])
            ->and($preset['strategy'])->toBeIn(['simple-grid', 'trailing-grid'])
            ->and($preset['levels_per_side'])->toBeLessThanOrEqual(10)
            ->and($preset['spacing_type'])->toBe('geometric');
    }
});

it('derives every preset within the default risk limits', function () {
    $center = Decimal::of('50000');
    $capital = Decimal::of('1000');

    $resolver = app(PresetResolver::class);

    foreach (config('bot_presets') as $key => $preset) {
        $values = $resolver->values($key, $center, $capital);

        expect($values)
            ->toMatchArray([
                'strategy_key' => $preset['strategy'],
                'level_count' => (int) $preset['levels_per_side'],
                'spacing_type' => $preset['spacing_type'],
                'spacing_value' => Decimal::of($preset['spacing_percent'])->div('100')->toString(),
            ]);

        if ($preset['strategy'] === 'trailing-grid') {
            expect($values['trailing_distance'])->toBe(
                $center->mul($preset['strategy_defaults']['trailing_distance_percent'])->div('100')->toString(),
            );
        }

        $configuration = [
            'center_price' => $center->toString(),
            'levels_per_side' => $values['level_count'],
            'spacing_type' => $values['spacing_type'],
            'spacing_value' => $values['spacing_value'],
            'order_quantity' => $values['order_quantity'],
        ];

        if (isset($values['trailing_distance'])) {
            $configuration['trailing_distance'] = $values['trailing_distance'];
        }

        $plan = (new GridPlanEvaluator(app(StrategyRegistry::class)))->evaluate(new GridPlanRequest(
            strategyKey: $values['strategy_key'],
            configuration: $configuration,
            symbol: 'BTC/USDT',
            capitalAllocation: $capital,
            limits: [
                'max_open_orders' => config('risk.defaults.max_open_orders'),
                'max_position_notional' => config('risk.defaults.max_position_notional'),
            ],
        ));

        expect($plan->isValid())->toBeTrue("Preset [{$key}] produced errors: ".implode(', ', array_map(
            fn ($problem) => $problem->message,
            $plan->errors(),
        )))
            ->and($plan->orderCount)->toBeLessThanOrEqual((int) config('risk.defaults.max_open_orders'))
            ->and($plan->buyNotional->lessThanOrEqual(config('risk.defaults.max_position_notional')))->toBeTrue();
    }
});
