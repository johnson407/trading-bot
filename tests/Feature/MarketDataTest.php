<?php

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function failingMarket(): void
{
    $failing = new class extends FakeExchangeClient
    {
        public function market(string $symbol): MarketData
        {
            throw new RuntimeException('exchange down');
        }
    };

    app()->instance(
        ExchangeClientFactory::class,
        new ExchangeClientFactory(overrides: ['binance' => $failing]),
    );
}

it('requires authentication for market data', function () {
    $this->getJson(route('bots.market-data', ['exchange' => 'binance', 'symbol' => 'BTC/USDT']))
        ->assertUnauthorized();
});

it('returns current market data for the setup form', function () {
    $user = User::factory()->create();
    $fake = new FakeExchangeClient(price: '50000');

    app()->instance(
        ExchangeClientFactory::class,
        new ExchangeClientFactory(overrides: ['binance' => $fake]),
    );

    $this->actingAs($user)
        ->getJson(route('bots.market-data', ['exchange' => 'binance', 'symbol' => 'BTC/USDT']))
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('market.last', '50000')
        ->assertJsonPath('market.price_precision', 2)
        ->assertJsonPath('market.amount_precision', 6)
        ->assertJsonPath('market.min_amount', '0.000001')
        ->assertJsonPath('market.min_notional', '1');
});

it('degrades gracefully when the exchange is unavailable', function () {
    $user = User::factory()->create();
    failingMarket();

    $this->actingAs($user)
        ->getJson(route('bots.market-data', ['exchange' => 'binance', 'symbol' => 'BTC/USDT']))
        ->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('market', null);
});

it('keeps a preview valid when market data is unavailable', function () {
    $user = User::factory()->create();
    failingMarket();

    $this->actingAs($user)
        ->postJson(route('bots.preview'), [
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'strategy_key' => 'simple-grid',
            'capital_allocation' => '1000',
            'center_price' => '50000',
            'level_count' => 3,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ])
        ->assertOk()
        ->assertJsonPath('market', null)
        ->assertJsonPath('plan.errors', [])
        ->assertJsonPath('plan.order_count', 6);
});

it('validates the exchange and symbol for market data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('bots.market-data', ['exchange' => 'unknown', 'symbol' => 'BTCUSDT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange', 'symbol']);
});
