<?php

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Exceptions\InvalidStrategyConfigurationException;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Domain\Support\Decimal;

it('builds and mutates strategy state', function () {
    $empty = StrategyState::empty();

    expect($empty->isNew())->toBeTrue()
        ->and($empty->version)->toBe(1)
        ->and($empty->toArray())->toBe(['version' => 1, 'data' => []]);

    $state = StrategyState::fromArray(['version' => 3, 'data' => ['a' => 1, 'b' => 2]]);

    expect($state->version)->toBe(3)
        ->and($state->isNew())->toBeFalse()
        ->and($state->get('a'))->toBe(1)
        ->and($state->get('missing', 'fallback'))->toBe('fallback')
        ->and($state->has('b'))->toBeTrue()
        ->and($state->has('missing'))->toBeFalse();

    expect($state->with(['c' => 3])->toArray())->toBe(['version' => 3, 'data' => ['c' => 3]])
        ->and($state->merge(['a' => 9, 'd' => 4])->toArray())->toBe(['version' => 3, 'data' => ['a' => 9, 'b' => 2, 'd' => 4]])
        ->and(StrategyState::fromArray(null)->isNew())->toBeTrue()
        ->and(StrategyState::fromArray(['version' => 2, 'data' => 'invalid'])->toArray())->toBe(['version' => 2, 'data' => []]);
});

it('reads typed strategy configuration values', function () {
    $config = new StrategyConfiguration('test', [
        'name' => 'Bot',
        'price' => '1.5',
        'count' => '3',
        'enabled' => true,
    ]);

    expect($config->key)->toBe('test')
        ->and($config->get('name'))->toBe('Bot')
        ->and($config->has('price'))->toBeTrue()
        ->and($config->string('name'))->toBe('Bot')
        ->and($config->decimal('price')->toString())->toBe('1.5')
        ->and($config->int('count'))->toBe(3)
        ->and($config->bool('enabled'))->toBeTrue();
});

it('rejects invalid typed configuration values', function (string $method, array $values) {
    $config = new StrategyConfiguration('test', $values);

    expect(fn () => $config->{$method}('key'))
        ->toThrow(InvalidStrategyConfigurationException::class);
})->with([
    'empty string' => ['string', ['key' => '']],
    'missing decimal' => ['decimal', []],
    'non-numeric int' => ['int', ['key' => 'three']],
    'non-bool' => ['bool', ['key' => 'yes']],
]);

it('creates and flips order intents', function () {
    $intent = OrderIntent::limit(OrderSide::Buy, '100', '2', 3, 'test');

    expect($intent->side)->toBe(OrderSide::Buy)
        ->and($intent->price->toString())->toBe('100')
        ->and($intent->quantity->toString())->toBe('2')
        ->and($intent->gridLevelSequence)->toBe(3)
        ->and($intent->reason)->toBe('test');

    $opposite = $intent->opposite();

    expect($opposite->side)->toBe(OrderSide::Sell)
        ->and($opposite->price->toString())->toBe('100')
        ->and($opposite->quantity->toString())->toBe('2')
        ->and($opposite->gridLevelSequence)->toBe(3)
        ->and($opposite->reason)->toBe('strategy.opposite')
        ->and(OrderIntent::limit('buy', Decimal::of('99'), Decimal::of('1'))->side)->toBe(OrderSide::Buy);
});

it('copies strategy decisions through each builder', function () {
    $state = StrategyState::empty();
    $level = GridLevelPlan::make(1, Decimal::of('100'), OrderSide::Buy);
    $order = OrderIntent::limit(OrderSide::Buy, '100', '1', 1);
    $idle = StrategyDecision::idle($state);

    expect($idle->levels)->toBe([])
        ->and($idle->orders)->toBe([])
        ->and($idle->stop)->toBeFalse()
        ->and($idle->events)->toBe([])
        ->and($idle->cancelClientOrderIds)->toBe([]);

    $withLevels = $idle->withLevels([$level]);
    $withOrders = $withLevels->withOrders([$order]);
    $withState = $withOrders->withState($state->with(['x' => 1]));
    $withStop = $withState->withStop(true);
    $withCancels = $withStop->withCancels(['c1']);

    expect($withLevels->levels)->toBe([$level])
        ->and($withOrders->orders)->toBe([$order])
        ->and($withState->state->get('x'))->toBe(1)
        ->and($withStop->stop)->toBeTrue()
        ->and($withCancels->cancelClientOrderIds)->toBe(['c1']);
});

it('looks up open orders in a bot snapshot', function () {
    $order = OpenOrderSnapshot::make('c1', OrderSide::Buy, '100', '1', 2);
    $bot = BotSnapshot::make(1, '0', '1000', '0', '0', '1000', [$order]);

    expect($bot->hasOpenOrderAt('100'))->toBeTrue()
        ->and($bot->hasOpenOrderAt('99'))->toBeFalse()
        ->and($bot->openOrderForSequence(2))->toBe($order)
        ->and($bot->openOrderForSequence(3))->toBeNull()
        ->and($bot->withOpenOrders([])->hasOpenOrderAt('100'))->toBeFalse();
});

it('exposes order side labels and transitions', function () {
    expect(OrderSide::Buy->opposite())->toBe(OrderSide::Sell)
        ->and(OrderSide::Sell->opposite())->toBe(OrderSide::Buy)
        ->and(OrderSide::Buy->label())->toBe('Buy');
});
