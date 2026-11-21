<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Strategies\MartingaleGridStrategy;

function martingaleInput(?StrategyState $state = null, array $overrides = []): StrategyInput
{
    return new StrategyInput(
        market: MarketSnapshot::make('BTC/USDT', '100'),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000'),
        config: new StrategyConfiguration(MartingaleGridStrategy::KEY, array_replace([
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'base_order_quantity' => '1',
            'size_multiplier' => '2',
            'max_order_quantity' => '10',
            'max_increases' => 10,
        ], $overrides)),
        state: $state ?? StrategyState::empty(),
    );
}

function martingaleState(StrategyState $state, string $side = 'buy', int $sequence = 1): StrategyState
{
    return $state;
}

it('increases the order size after successive fills', function () {
    $strategy = new MartingaleGridStrategy;
    $input = martingaleInput();
    $initial = $strategy->initialize($input);

    $first = $strategy->onFill(
        $input->withState($initial->state),
        FillEvent::make('f1', OrderSide::Buy, '90', '1', '0', 'c1', 1),
    );
    $second = $strategy->onFill(
        $input->withState($first->state),
        FillEvent::make('f2', OrderSide::Buy, '90', '2', '0', 'c2', 1),
    );

    expect($first->state->get('current_quantity'))->toBe('2')
        ->and($second->state->get('current_quantity'))->toBe('4')
        ->and($second->state->get('increase_count'))->toBe(2)
        ->and($second->orders[0]->quantity->toString())->toBe('4');
});

it('caps the order size at the hard maximum and reports the cap event', function () {
    $strategy = new MartingaleGridStrategy;
    $input = martingaleInput(overrides: ['max_order_quantity' => '3']);
    $initial = $strategy->initialize($input);

    $first = $strategy->onFill(
        $input->withState($initial->state),
        FillEvent::make('f1', OrderSide::Buy, '90', '1', '0', 'c1', 1),
    );
    $second = $strategy->onFill(
        $input->withState($first->state),
        FillEvent::make('f2', OrderSide::Buy, '90', '3', '0', 'c2', 1),
    );

    expect($first->state->get('current_quantity'))->toBe('2')
        ->and($second->state->get('current_quantity'))->toBe('3')
        ->and($second->events)->toHaveCount(1)
        ->and($second->events[0]['type'])->toBe('martingale.cap_reached')
        ->and($second->orders[0]->quantity->toString())->toBe('3');
});

it('stops increasing after the configured number of increases', function () {
    $strategy = new MartingaleGridStrategy;
    $input = martingaleInput(overrides: ['max_increases' => 2, 'max_order_quantity' => '100']);
    $initial = $strategy->initialize($input);

    $first = $strategy->onFill($input->withState($initial->state), FillEvent::make('f1', OrderSide::Buy, '90', '1', '0', 'c1', 1));
    $second = $strategy->onFill($input->withState($first->state), FillEvent::make('f2', OrderSide::Buy, '90', '2', '0', 'c2', 1));
    $third = $strategy->onFill($input->withState($second->state), FillEvent::make('f3', OrderSide::Buy, '90', '4', '0', 'c3', 1));

    expect($second->state->get('current_quantity'))->toBe('4')
        ->and($second->state->get('cap_reached'))->toBeTrue()
        ->and($third->state->get('current_quantity'))->toBe('4')
        ->and($third->state->get('increase_count'))->toBe(2);
});

it('registers martingale-grid', function () {
    expect(app(StrategyRegistry::class)->get('martingale-grid'))->toBeInstanceOf(MartingaleGridStrategy::class);
});
