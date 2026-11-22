<?php

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Enums\GridPlanProblemSeverity;
use App\Domain\Grid\GridPlanEvaluator;
use App\Domain\Grid\ValueObjects\GridPlan;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;

function evaluateGridPlan(array $overrides = [], array $request = []): GridPlan
{
    return (new GridPlanEvaluator(app(StrategyRegistry::class)))->evaluate(new GridPlanRequest(
        strategyKey: $request['strategyKey'] ?? 'simple-grid',
        configuration: array_replace(gridPlanConfiguration(), $overrides),
        symbol: $request['symbol'] ?? 'BTC/USDT',
        capitalAllocation: $request['capitalAllocation'] ?? '1000',
        marketPrice: $request['marketPrice'] ?? null,
        limits: $request['limits'] ?? null,
        market: $request['market'] ?? null,
    ));
}

function gridPlanConfiguration(): array
{
    return [
        'center_price' => '50000',
        'levels_per_side' => 3,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ];
}

it('evaluates an arithmetic ladder with per-level sizes and totals', function () {
    $plan = evaluateGridPlan();

    expect($plan->isValid())->toBeTrue()
        ->and($plan->errors())->toBe([])
        ->and(array_map(fn ($level) => $level->price->toString(), $plan->levels))
        ->toBe(['49700', '49800', '49900', '50100', '50200', '50300'])
        ->and(array_map(fn ($level) => $level->side->value, $plan->levels))
        ->toBe(['buy', 'buy', 'buy', 'sell', 'sell', 'sell'])
        ->and(array_map(fn ($level) => $level->quantity->toString(), $plan->levels))
        ->toBe(['0.001', '0.001', '0.001', '0.001', '0.001', '0.001'])
        ->and(array_map(fn ($level) => $level->notional->toString(), $plan->levels))
        ->toBe(['49.7', '49.8', '49.9', '50.1', '50.2', '50.3'])
        ->and($plan->orderCount)->toBe(6)
        ->and($plan->buyNotional->toString())->toBe('149.4')
        ->and($plan->sellNotional->toString())->toBe('150.6');
});

it('evaluates a geometric ladder', function () {
    $plan = evaluateGridPlan([
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'geometric',
        'spacing_value' => '0.1',
        'order_quantity' => '1',
    ]);

    expect($plan->isValid())->toBeTrue()
        ->and(array_map(fn ($level) => $level->price->toString(), $plan->levels))
        ->toBe(['82.6446280991735537', '90.9090909090909091', '110', '121'])
        ->and(array_map(fn ($level) => $level->side->value, $plan->levels))
        ->toBe(['buy', 'buy', 'sell', 'sell']);
});

it('reports a hard stop for geometric spacing of one or more', function () {
    $plan = evaluateGridPlan([
        'spacing_type' => 'geometric',
        'spacing_value' => '1',
    ]);

    expect($plan->isValid())->toBeFalse()
        ->and($plan->errors())->toHaveCount(1)
        ->and($plan->errors()[0]->severity)->toBe(GridPlanProblemSeverity::Error)
        ->and($plan->errors()[0]->field)->toBe('spacing_value')
        ->and($plan->errors()[0]->code)->toBe('plan.invalid_configuration');
});

it('reports a hard stop for a non-positive level price', function () {
    $plan = evaluateGridPlan([
        'center_price' => '100',
        'spacing_value' => '100',
    ]);

    expect($plan->isValid())->toBeFalse()
        ->and($plan->errors()[0]->field)->toBe('spacing_value')
        ->and($plan->errors()[0]->message)->toContain('positive level price');
});

it('reports a hard stop for duplicate level prices', function () {
    $plan = evaluateGridPlan([
        'spacing_value' => '0.000000000000000001',
    ]);

    expect($plan->isValid())->toBeFalse()
        ->and($plan->errors()[0]->field)->toBe('spacing_value')
        ->and($plan->errors()[0]->message)->toContain('duplicate');
});

it('rejects a quantity below the exchange minimum amount', function () {
    $plan = evaluateGridPlan([], [
        'market' => MarketData::make('BTC/USDT', '50000', minAmount: '0.01', minNotional: '0'),
    ]);

    expect($plan->isValid())->toBeFalse()
        ->and($plan->errors()[0]->field)->toBe('order_quantity')
        ->and($plan->errors()[0]->code)->toBe('plan.minimum_amount');
});

it('rejects a notional below the exchange minimum notional', function () {
    $plan = evaluateGridPlan([], [
        'market' => MarketData::make('BTC/USDT', '50000', minAmount: '0', minNotional: '100'),
    ]);

    expect($plan->isValid())->toBeFalse()
        ->and($plan->errors()[0]->field)->toBe('order_quantity')
        ->and($plan->errors()[0]->code)->toBe('plan.minimum_notional');
});

it('does not apply exchange minimums when market data is unavailable', function () {
    $plan = evaluateGridPlan();

    expect($plan->isValid())->toBeTrue();
});

it('returns advisory warnings without blocking a valid setup', function () {
    $plan = evaluateGridPlan([], [
        'marketPrice' => '10000',
        'limits' => [
            'max_open_orders' => 4,
            'max_position_notional' => Decimal::of('100'),
        ],
    ]);

    $codes = array_map(fn ($problem) => $problem->code, $plan->warnings());

    expect($plan->isValid())->toBeTrue()
        ->and($plan->errors())->toBe([])
        ->and($codes)->toContain('plan.max_open_orders')
        ->and($codes)->toContain('plan.max_position_notional')
        ->and($codes)->toContain('plan.center_price_band')
        ->and($codes)->toContain('plan.unfunded_sells');
});
