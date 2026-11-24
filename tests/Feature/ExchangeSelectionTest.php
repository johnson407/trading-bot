<?php

use App\Actions\StartBot;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Models\Bot;
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

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Running)
        ->and($bot->fresh()->status)->toBe(BotStatus::Idle)
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
