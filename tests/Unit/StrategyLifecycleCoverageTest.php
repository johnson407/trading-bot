<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Strategies\DynamicGridStrategy;
use App\Strategies\MartingaleGridStrategy;
use App\Strategies\TrailingGridStrategy;

function lifecycleInput(string $key, array $config, ?StrategyState $state = null): StrategyInput
{
    return new StrategyInput(
        market: MarketSnapshot::make('BTC/USDT', '100'),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000'),
        config: new StrategyConfiguration($key, $config),
        state: $state ?? StrategyState::empty(),
    );
}

function trailingConfig(array $overrides = []): array
{
    return array_replace([
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'trailing_distance' => '10',
    ], $overrides);
}

function martingaleConfig(array $overrides = []): array
{
    return array_replace([
        'center_price' => '100',
        'levels_per_side' => 1,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
        'base_order_quantity' => '1',
        'size_multiplier' => '2',
        'max_order_quantity' => '10',
        'max_increases' => 10,
    ], $overrides);
}

function dynamicConfig(array $overrides = []): array
{
    return array_replace([
        'center_price' => '100',
        'levels_per_side' => 1,
        'order_quantity' => '1',
        'min_spacing' => '0.5',
        'max_spacing' => '20',
        'initial_spacing' => '1',
        'volatility_multiplier' => '1',
        'recalculation_interval_seconds' => 60,
    ], $overrides);
}

it('covers trailing lifecycle decisions', function () {
    $strategy = new TrailingGridStrategy;
    $input = lifecycleInput(TrailingGridStrategy::KEY, trailingConfig());
    $initialized = $strategy->initialize($input);

    expect($strategy->shouldStop($input))->toBeFalse()
        ->and($strategy->getGridLevels($input->withState($initialized->state)))->toHaveCount(4)
        ->and($strategy->initialize($input->withState($initialized->state))->orders)->toBe([]);

    $cancel = $strategy->onCancel($input->withState($initialized->state), new CancelEvent('c1', 2, 'manual'));
    expect($cancel->state->get('last_canceled_client_order_id'))->toBe('c1');

    $fill = FillEvent::make('f1', OrderSide::Buy, '100', '1', '0', 'c1', 99);
    expect($strategy->onFill($input->withState($initialized->state), $fill)->orders)->toBe([]);
});

it('covers martingale lifecycle decisions', function () {
    $strategy = new MartingaleGridStrategy;
    $input = lifecycleInput(MartingaleGridStrategy::KEY, martingaleConfig());
    $initialized = $strategy->initialize($input);

    expect($strategy->shouldStop($input))->toBeFalse()
        ->and($strategy->getGridLevels($input->withState($initialized->state)))->toHaveCount(2)
        ->and($strategy->initialize($input->withState($initialized->state))->orders)->toBe([]);

    $cancel = $strategy->onCancel($input->withState($initialized->state), new CancelEvent('c1', 2, 'manual'));
    expect($cancel->state->get('last_canceled_client_order_id'))->toBe('c1');

    $fill = FillEvent::make('f1', OrderSide::Buy, '100', '1', '0', 'c1', 99);
    expect($strategy->onFill($input->withState($initialized->state), $fill)->orders)->toBe([]);
});

it('covers dynamic lifecycle decisions', function () {
    $strategy = new DynamicGridStrategy;
    $input = lifecycleInput(DynamicGridStrategy::KEY, dynamicConfig());
    $initialized = $strategy->initialize($input);

    expect($strategy->shouldStop($input))->toBeFalse()
        ->and($strategy->getGridLevels($input))->toHaveCount(2)
        ->and($strategy->getGridLevels($input->withState($initialized->state)))->toHaveCount(2)
        ->and($strategy->initialize($input->withState($initialized->state))->orders)->toBe([]);

    $cancel = $strategy->onCancel($input->withState($initialized->state), new CancelEvent('c1', 2, 'manual'));
    expect($cancel->state->get('last_canceled_client_order_id'))->toBe('c1');

    $fill = FillEvent::make('f1', OrderSide::Buy, '100', '1', '0', 'c1', 99);
    expect($strategy->onFill($input->withState($initialized->state), $fill)->orders)->toBe([]);
});

it('rejects invalid trailing configurations', function (array $overrides) {
    $strategy = app(StrategyRegistry::class)->get(TrailingGridStrategy::KEY);

    expect(fn () => $strategy->onTick(lifecycleInput(TrailingGridStrategy::KEY, trailingConfig($overrides))))
        ->toThrow(InvalidStrategyConfigurationException::class);
})->with([
    'center' => [['center_price' => '0']],
    'levels' => [['levels_per_side' => 'x']],
    'spacing type' => [['spacing_type' => 'weird']],
    'quantity' => [['order_quantity' => '0']],
    'trailing distance' => [['trailing_distance' => '0']],
]);

it('rejects invalid martingale configurations', function (array $overrides) {
    $strategy = app(StrategyRegistry::class)->get(MartingaleGridStrategy::KEY);

    expect(fn () => $strategy->onTick(lifecycleInput(MartingaleGridStrategy::KEY, martingaleConfig($overrides))))
        ->toThrow(InvalidStrategyConfigurationException::class);
})->with([
    'center' => [['center_price' => '0']],
    'levels' => [['levels_per_side' => 'x']],
    'spacing type' => [['spacing_type' => 'weird']],
    'base quantity' => [['base_order_quantity' => '0']],
]);

it('rejects invalid dynamic configurations', function (array $overrides, bool $initialized = false) {
    $strategy = app(StrategyRegistry::class)->get(DynamicGridStrategy::KEY);
    $input = lifecycleInput(DynamicGridStrategy::KEY, dynamicConfig($overrides));

    if ($initialized) {
        $valid = lifecycleInput(DynamicGridStrategy::KEY, dynamicConfig());
        $input = $input->withState($strategy->initialize($valid)->state);
    }

    expect(fn () => $strategy->onTick($input))
        ->toThrow(InvalidStrategyConfigurationException::class);
})->with([
    'center' => [['center_price' => '0']],
    'levels' => [['levels_per_side' => 'x']],
    'quantity' => [['order_quantity' => '0']],
    'interval' => [['recalculation_interval_seconds' => -1], true],
]);
