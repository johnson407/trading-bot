<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Exceptions\UnknownStrategyException;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use Tests\Fixtures\FakeStrategy;

function fakeInput(string $price = '100', ?StrategyState $state = null): StrategyInput
{
    return new StrategyInput(
        market: MarketSnapshot::make('BTC/USDT', $price),
        bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000'),
        config: new StrategyConfiguration('fake', []),
        state: $state ?? StrategyState::empty(),
    );
}

it('resolves a registered strategy from the container registry', function () {
    $strategy = new FakeStrategy;
    $registry = app(StrategyRegistry::class);
    $registry->register($strategy);

    expect(app(StrategyRegistry::class))->toBe($registry)
        ->and($registry->has('fake'))->toBeTrue()
        ->and($registry->get('fake'))->toBe($strategy)
        ->and($registry->keys())->toContain('fake')->toContain('simple-grid');
});

it('covers the full strategy contract for a fake strategy', function () {
    $strategy = new FakeStrategy;
    $input = fakeInput();

    $initialized = $strategy->initialize($input);
    expect($initialized->state->get('initialized'))->toBeTrue()
        ->and($initialized->levels)->toHaveCount(1)
        ->and($strategy->initialize($input->withState($initialized->state))->state->data)
        ->toBe($initialized->state->data);

    $tick = $strategy->onTick($input->withState($initialized->state));
    expect($tick->state->get('ticks'))->toBe(1)
        ->and($tick->orders)->toHaveCount(1)
        ->and($tick->orders[0]->side)->toBe(OrderSide::Buy);

    $fill = FillEvent::make('fill-1', OrderSide::Buy, '100', '1', '0', 'client-1', 1);
    $afterFill = $strategy->onFill($input->withState($tick->state), $fill);
    expect($afterFill->state->get('fills'))->toBe(1)
        ->and($afterFill->orders[0]->side)->toBe(OrderSide::Sell);

    $afterCancel = $strategy->onCancel($input->withState($afterFill->state), new CancelEvent('client-1', 1));
    expect($afterCancel->state->get('cancels'))->toBe(1);

    expect($strategy->shouldStop(fakeInput('1000')))->toBeTrue()
        ->and($strategy->shouldStop(fakeInput('100')))->toBeFalse()
        ->and($strategy->getGridLevels($input)[0]->price->toString())->toBe('100');
});

it('rejects an unknown strategy with the known keys', function () {
    app(StrategyRegistry::class)->register(new FakeStrategy);

    expect(fn () => app(StrategyRegistry::class)->get('missing'))
        ->toThrow(UnknownStrategyException::class, 'Known strategies: simple-grid');
});
