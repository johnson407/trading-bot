<?php

use App\Actions\CancelOrder;
use App\Actions\StartBot;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('checks the live start balance against the bot exchange, not the default', function () {
    config(['trading.live_enabled' => true]);

    $binance = new FakeExchangeClient(quoteBalance: '0');
    $kraken = new FakeExchangeClient(quoteBalance: '1000');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(overrides: [
        'binance' => $binance,
        'kraken' => $kraken,
    ]));

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'name' => 'Kraken grid',
        'exchange' => 'kraken',
        'status' => BotStatus::Idle,
        'capital_allocation' => '1000',
    ]);

    app(StartBot::class)->handle($bot, new LiveStartConfirmation('Kraken grid on kraken'));

    expect($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and($kraken->balanceCalls)->toBe(1)
        ->and($binance->balanceCalls)->toBe(0);
});

it('builds a live client bound to the bot exchange', function () {
    config(['trading.default_exchange' => 'binance']);

    $binance = Bot::factory()->live()->create(['exchange' => 'binance']);
    $kraken = Bot::factory()->live()->create(['exchange' => 'kraken']);

    $factory = new ExchangeClientFactory;

    expect($factory->forBot($binance)->exchange())->toBe('binance')
        ->and($factory->forBot($kraken)->exchange())->toBe('kraken');
});

it('uses the client bound to each bot exchange for market data', function () {
    $binanceFake = new FakeExchangeClient(price: '100');
    $krakenFake = new FakeExchangeClient(price: '200');

    $factory = new ExchangeClientFactory(overrides: [
        'binance' => $binanceFake,
        'kraken' => $krakenFake,
    ]);

    $binance = Bot::factory()->create(['exchange' => 'binance']);
    $kraken = Bot::factory()->create(['exchange' => 'kraken']);

    expect($factory->forBot($binance)->market('BTC/USDT')->last->toString())->toBe('100')
        ->and($factory->forBot($kraken)->market('BTC/USDT')->last->toString())->toBe('200');
});


it('sends live order and cancel traffic to the client for the bot exchange', function () {
    config([
        'trading.live_enabled' => true,
        'trading.exchanges' => ['binance', 'kraken'],
    ]);

    $binanceFake = new FakeExchangeClient(price: '95');
    $krakenFake = new FakeExchangeClient(price: '95');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(overrides: [
        'binance' => $binanceFake,
        'kraken' => $krakenFake,
    ]));

    $bot = Bot::factory()->running()->live()->create([
        'exchange' => 'kraken',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'quote_inventory' => '1000',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'fee_rate' => '0',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'simple-grid',
        'configuration' => [
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ],
        'state' => null,
    ]);

    app(BotTickPipeline::class)->tick($bot);

    expect($bot->fresh()->exchange)->toBe('kraken')
        ->and($krakenFake->placeCalls)->toHaveCount(2)
        ->and($binanceFake->placeCalls)->toBe([]);

    $order = Order::query()->where('bot_id', $bot->id)->firstOrFail();
    app(CancelOrder::class)->handle($order);

    expect($krakenFake->cancelCalls)->toHaveCount(1)
        ->and($binanceFake->cancelCalls)->toBe([]);
});
