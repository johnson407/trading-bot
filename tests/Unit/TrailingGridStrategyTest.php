<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Strategies\TrailingGridStrategy;

function trailingInput(string $marketPrice, ?StrategyState $state = null, array $openOrders = []): StrategyInput
{
    return new StrategyInput(
        market: MarketSnapshot::make('BTC/USDT', $marketPrice),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000', $openOrders),
        config: new StrategyConfiguration(TrailingGridStrategy::KEY, [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'trailing_distance' => '10',
        ]),
        state: $state ?? StrategyState::empty(),
    );
}

it('keeps the grid center while price stays inside the trailing distance', function () {
    $strategy = new TrailingGridStrategy;
    $initial = $strategy->initialize(trailingInput('100'));

    $decision = $strategy->onTick(trailingInput('105', $initial->state));

    expect($decision->state->get('center_price'))->toBe('100')
        ->and($decision->state->get('recenter_count'))->toBe(0)
        ->and($decision->orders)->toHaveCount(4)
        ->and($decision->cancelClientOrderIds)->toBe([]);
});

it('recenters the grid after price moves beyond the trailing distance', function () {
    $strategy = new TrailingGridStrategy;
    $initial = $strategy->initialize(trailingInput('100'));

    $decision = $strategy->onTick(trailingInput('111', $initial->state));

    expect($decision->state->get('center_price'))->toBe('111')
        ->and($decision->state->get('recenter_count'))->toBe(1)
        ->and(array_map(fn ($level) => $level->price->toString(), $decision->levels))
        ->toBe(['91', '101', '121', '131'])
        ->and($decision->orders)->toHaveCount(4);
});

it('cancels out-of-range orders and replenishes the new grid', function () {
    $strategy = new TrailingGridStrategy;
    $initial = $strategy->initialize(trailingInput('100'));

    $decision = $strategy->onTick(trailingInput('111', $initial->state, [
        OpenOrderSnapshot::make('old-outside', OrderSide::Buy, '80', '1', 1),
        OpenOrderSnapshot::make('still-inside', OrderSide::Buy, '101', '1', 2),
    ]));

    expect($decision->cancelClientOrderIds)->toBe(['old-outside'])
        ->and($decision->orders)->toHaveCount(3)
        ->and(array_map(fn ($order) => $order->price->toString(), $decision->orders))
        ->toBe(['91', '121', '131']);
});

it('replaces a filled trailing level with the adjacent opposite order', function () {
    $strategy = new TrailingGridStrategy;
    $initial = $strategy->initialize(trailingInput('100'));

    $decision = $strategy->onFill(
        trailingInput('100', $initial->state),
        App\Domain\Strategy\ValueObjects\FillEvent::make('fill-1', OrderSide::Buy, '90', '1', '0', 'client-1', 2),
    );

    expect($decision->orders[0]->side)->toBe(OrderSide::Sell)
        ->and($decision->orders[0]->price->toString())->toBe('110');
});

it('registers trailing-grid', function () {
    expect(app(StrategyRegistry::class)->get('trailing-grid'))->toBeInstanceOf(TrailingGridStrategy::class);
});
