<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Strategies\SimpleGridStrategy;

function simpleInput(array $config, array $openOrders = []): StrategyInput
{
    return new StrategyInput(
        market: MarketSnapshot::make('BTC/USDT', '100'),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000', $openOrders),
        config: new StrategyConfiguration(SimpleGridStrategy::KEY, $config),
        state: StrategyState::empty(),
    );
}

function simpleConfig(array $overrides = []): array
{
    return array_replace([
        'center_price' => '100',
        'levels_per_side' => 2,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
    ], $overrides);
}

it('generates arithmetic levels below and above the center', function () {
    $strategy = new SimpleGridStrategy;
    $decision = $strategy->initialize(simpleInput(simpleConfig()));

    expect($decision->levels)->toHaveCount(4)
        ->and(array_map(fn ($level) => $level->price->toString(), $decision->levels))
        ->toBe(['80', '90', '110', '120'])
        ->and(array_map(fn ($level) => $level->side, $decision->levels))
        ->toBe([OrderSide::Buy, OrderSide::Buy, OrderSide::Sell, OrderSide::Sell])
        ->and(array_map(fn ($level) => $level->sequence, $decision->levels))
        ->toBe([1, 2, 3, 4]);
});

it('generates geometric levels using the configured percentage', function () {
    $strategy = new SimpleGridStrategy;
    $decision = $strategy->initialize(simpleInput(simpleConfig([
        'spacing_type' => 'geometric',
        'spacing_value' => '0.1',
    ])));

    expect(array_map(fn ($level) => $level->price->toString(), $decision->levels))
        ->toBe(['82.6446280991735537', '90.9090909090909091', '110', '121']);
});

it('places orders only at levels without an open order', function () {
    $strategy = new SimpleGridStrategy;
    $input = simpleInput(simpleConfig([
        'level_count' => 2,
    ]), [
        OpenOrderSnapshot::make('existing', OrderSide::Buy, '90', '1', 2),
    ]);

    $decision = $strategy->onTick($input);

    expect($decision->orders)->toHaveCount(3)
        ->and(array_map(fn ($order) => $order->price->toString(), $decision->orders))
        ->toBe(['80', '110', '120']);
});

it('replaces a filled level with the adjacent opposite order', function () {
    $strategy = new SimpleGridStrategy;
    $initial = $strategy->initialize(simpleInput(simpleConfig()));

    $decision = $strategy->onFill(
        simpleInput(simpleConfig())->withState($initial->state),
        FillEvent::make('fill-1', OrderSide::Buy, '90', '1', '0', 'client-1', 2),
    );

    expect($decision->orders)->toHaveCount(1)
        ->and($decision->orders[0]->side)->toBe(OrderSide::Sell)
        ->and($decision->orders[0]->price->toString())->toBe('110')
        ->and($decision->orders[0]->gridLevelSequence)->toBe(3)
        ->and($decision->state->get('fill_count'))->toBe(1);
});

it('rejects invalid spacing, level counts and missing parameters', function (array $config) {
    (new SimpleGridStrategy)->initialize(simpleInput($config));
})->with([
    'zero spacing' => [simpleConfig(['spacing_value' => '0'])],
    'negative spacing' => [simpleConfig(['spacing_value' => '-1'])],
    'geometric above one' => [simpleConfig(['spacing_type' => 'geometric', 'spacing_value' => '1'])],
    'zero levels' => [simpleConfig(['levels_per_side' => 0])],
    'missing center' => [array_diff_key(simpleConfig(), ['center_price' => true])],
])->throws(InvalidStrategyConfigurationException::class);

it('rejects duplicate generated levels', function () {
    (new SimpleGridStrategy)->initialize(simpleInput(simpleConfig([
        'spacing_value' => '0.000000000000000001',
    ])));
})->throws(InvalidStrategyConfigurationException::class, 'duplicate grid level price');

it('registers simple-grid in the container registry', function () {
    $strategy = app(StrategyRegistry::class)->get(SimpleGridStrategy::KEY);

    expect($strategy)->toBeInstanceOf(SimpleGridStrategy::class)
        ->and($strategy->key())->toBe('simple-grid');
});

it('returns idle when initialized with existing state', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());
    $initialized = $strategy->initialize($input);

    $decision = $strategy->initialize($input->withState($initialized->state));

    expect($decision->state->isNew())->toBeFalse()
        ->and($decision->orders)->toBe([]);
});

it('returns grid levels for an initialized strategy', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());
    $initialized = $strategy->initialize($input);

    $levels = $strategy->getGridLevels($input->withState($initialized->state));

    expect($levels)->toHaveCount(4)
        ->and($levels[0]->price->toString())->toBe('80');
});

it('handles cancel events', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());

    $decision = $strategy->onCancel($input, new \App\Domain\Strategy\ValueObjects\CancelEvent('client-1', 2, 'manual'));

    expect($decision->state->get('last_canceled_client_order_id'))->toBe('client-1');
});

it('never stops on its own', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;

    expect($strategy->shouldStop(simpleInput(simpleConfig())))->toBeFalse();
});

it('returns no opposite order for a fill without a grid sequence', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());

    $decision = $strategy->onFill($input, \App\Domain\Strategy\ValueObjects\FillEvent::make('f1', \App\Domain\Order\Enums\OrderSide::Buy, '90', '1', '0', 'client-1', null));

    expect($decision->orders)->toBe([]);
});

it('returns no opposite order when the adjacent level is missing', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());

    $decision = $strategy->onFill($input, \App\Domain\Strategy\ValueObjects\FillEvent::make('f1', \App\Domain\Order\Enums\OrderSide::Buy, '90', '1', '0', 'client-1', 99));

    expect($decision->orders)->toBe([]);
});

it('rejects invalid simple grid configurations', function (array $config) {
    $strategy = new \App\Strategies\SimpleGridStrategy;

    expect(fn () => $strategy->onTick(simpleInput($config)))->toThrow(\App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException::class);
})->with([
    'non-positive center' => [['center_price' => '0', 'levels_per_side' => 1, 'spacing_type' => 'arithmetic', 'spacing_value' => '10', 'order_quantity' => '1']],
    'non-integer levels' => [['center_price' => '100', 'levels_per_side' => 'x', 'spacing_type' => 'arithmetic', 'spacing_value' => '10', 'order_quantity' => '1']],
    'unsupported spacing' => [['center_price' => '100', 'levels_per_side' => 1, 'spacing_type' => 'weird', 'spacing_value' => '10', 'order_quantity' => '1']],
    'dynamic spacing' => [['center_price' => '100', 'levels_per_side' => 1, 'spacing_type' => 'dynamic', 'spacing_value' => '10', 'order_quantity' => '1']],
    'non-positive quantity' => [['center_price' => '100', 'levels_per_side' => 1, 'spacing_type' => 'arithmetic', 'spacing_value' => '10', 'order_quantity' => '0']],
]);

it('does not re-arm a filled level until its paired opposite fills', function () {
    $strategy = new \App\Strategies\SimpleGridStrategy;
    $input = simpleInput(simpleConfig());
    $initialized = $strategy->initialize($input);

    $buyFill = \App\Domain\Strategy\ValueObjects\FillEvent::make('f1', \App\Domain\Order\Enums\OrderSide::Buy, '90', '1', '0', 'client-1', 2);
    $afterBuy = $strategy->onFill($input->withState($initialized->state), $buyFill);

    $levelsAfterBuy = collect($strategy->onTick($input->withState($afterBuy->state))->orders);
    expect($levelsAfterBuy->contains(fn ($order) => $order->price->toString() === '90'))->toBeFalse();

    $sellFill = \App\Domain\Strategy\ValueObjects\FillEvent::make('f2', \App\Domain\Order\Enums\OrderSide::Sell, '110', '1', '0', 'client-2', 3);
    $afterSell = $strategy->onFill($input->withState($afterBuy->state), $sellFill);

    $levelsAfterSell = collect($strategy->onTick($input->withState($afterSell->state))->orders);
    expect($levelsAfterSell->contains(fn ($order) => $order->price->toString() === '90'))->toBeTrue();
});
