<?php

use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function ruleBot(array $config): array
{
    $bot = Bot::factory()->running()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'quote_inventory' => '1000',
        'fee_rate' => '0',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'simple-grid',
        'configuration' => array_merge([
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ], $config),
        'state' => null,
    ]);

    $fake = new FakeExchangeClient(price: '95');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new App\Services\Exchange\PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('rounds prices and quantities to the exchange trading rules', function () {
    [$bot, $fake] = ruleBot([
        'spacing_value' => '3.333333',
        'order_quantity' => '0.123456789',
    ]);

    $result = app(BotTickPipeline::class)->tick($bot);

    $order = App\Models\Order::query()->firstOrFail();

    expect($result->successful())->toBeTrue()
        ->and($order->price)->toBe('96.6700000000000000')
        ->and($order->quantity)->toBe('0.1234570000000000');
});

it('rejects an order below the exchange minimum amount', function () {
    [$bot, $fake] = ruleBot(['order_quantity' => '0.0000001']);

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($fake->placeCalls)->toBe([])
        ->and($result->ordersPlaced)->toBe(0)
        ->and($bot->riskEvents()->where('type', 'order.min_amount')->exists())->toBeTrue();
});

it('rejects an order below the exchange minimum notional', function () {
    [$bot, $fake] = ruleBot(['order_quantity' => '0.001']);

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($fake->placeCalls)->toBe([])
        ->and($result->ordersPlaced)->toBe(0)
        ->and($bot->riskEvents()->where('type', 'order.min_notional')->exists())->toBeTrue();
});
