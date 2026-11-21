<?php

use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Domain\Support\Decimal;
use App\Strategies\DynamicGridStrategy;
use Carbon\CarbonImmutable;

function dynamicInput(array $recent, string $price = '100', ?StrategyState $state = null, array $overrides = [], ?string $at = null): StrategyInput
{
    return new StrategyInput(
        market: new MarketSnapshot(
            symbol: 'BTC/USDT',
            price: Decimal::of($price),
            recentPrices: array_map(fn ($value) => Decimal::of($value)->toString(), $recent),
            timestamp: new DateTimeImmutable($at ?? '2026-01-01T00:00:00+00:00'),
        ),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000'),
        config: new StrategyConfiguration(DynamicGridStrategy::KEY, array_replace([
            'center_price' => '100',
            'levels_per_side' => 1,
            'order_quantity' => '1',
            'min_spacing' => '0.5',
            'max_spacing' => '20',
            'volatility_multiplier' => '1',
            'initial_spacing' => '1',
            'recalculation_interval_seconds' => 60,
        ], $overrides)),
        state: $state ?? StrategyState::empty(),
    );
}

it('widens spacing when recent volatility rises', function () {
    $strategy = new DynamicGridStrategy;
    $initial = $strategy->initialize(dynamicInput(['100', '100.5']));
    $narrow = Decimal::of($initial->state->get('spacing_value'));

    $wideDecision = $strategy->onTick(dynamicInput(['100', '110'], state: $initial->state, at: '2026-01-01T00:02:00+00:00'));
    $wide = Decimal::of($wideDecision->state->get('spacing_value'));

    expect($wide->greaterThan($narrow))->toBeTrue()
        ->and($wide->lessThanOrEqual('20'))->toBeTrue();
});

it('narrows spacing when recent volatility falls', function () {
    $strategy = new DynamicGridStrategy;
    $initial = $strategy->initialize(dynamicInput(['100', '110']));
    $wide = Decimal::of($initial->state->get('spacing_value'));

    $narrowDecision = $strategy->onTick(dynamicInput(['100', '101'], state: $initial->state, at: '2026-01-01T00:02:00+00:00'));
    $narrow = Decimal::of($narrowDecision->state->get('spacing_value'));

    expect($narrow->lessThan($wide))->toBeTrue()
        ->and($narrow->greaterThanOrEqual('0.5'))->toBeTrue();
});

it('clamps calculated spacing to the configured bounds', function () {
    $strategy = new DynamicGridStrategy;

    $high = $strategy->initialize(dynamicInput(['100', '110'], overrides: ['volatility_multiplier' => '1000']));
    $low = $strategy->initialize(dynamicInput(['100', '110'], overrides: ['volatility_multiplier' => '0.00001']));

    expect($high->state->get('spacing_value'))->toBe('20')
        ->and($low->state->get('spacing_value'))->toBe('0.5');
});

it('retains spacing until the recalculation interval elapses', function () {
    $strategy = new DynamicGridStrategy;
    $initial = $strategy->initialize(dynamicInput(['100', '100.5']));

    $suppressed = $strategy->onTick(dynamicInput(
        ['100', '150'],
        state: $initial->state,
        at: '2026-01-01T00:00:30+00:00',
    ));

    $elapsed = $strategy->onTick(dynamicInput(
        ['100', '150'],
        state: $initial->state,
        at: '2026-01-01T00:02:00+00:00',
    ));

    expect($suppressed->state->get('spacing_value'))->toBe($initial->state->get('spacing_value'))
        ->and($suppressed->state->get('recalculation_count'))->toBe(0)
        ->and($elapsed->state->get('spacing_value'))->toBe('20');
});

it('registers dynamic-grid', function () {
    expect(app(StrategyRegistry::class)->get('dynamic-grid'))->toBeInstanceOf(DynamicGridStrategy::class);
});
