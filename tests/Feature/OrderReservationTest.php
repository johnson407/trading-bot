<?php

use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function reservationBot(FakeExchangeClient $fake): Bot
{
    config(['trading.live_enabled' => true]);

    $bot = Bot::factory()->running()->live()->create([
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

    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    return $bot;
}

it('persists the pending order before calling the exchange', function () {
    $fake = new FakeExchangeClient(price: '95');
    $observations = [];
    $fake->onPlace = function (string $clientOrderId) use (&$observations): void {
        $observations[$clientOrderId] = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->where('status', 'pending')
            ->exists();
    };

    $bot = reservationBot($fake);
    $result = app(BotTickPipeline::class)->tick($bot);

    expect($result->successful())->toBeTrue()
        ->and($bot->fresh()->last_error)->toBeNull()
        ->and($observations)->not->toBeEmpty()
        ->and(array_filter($observations))->toHaveCount(count($observations));
});

it('does not place a second sell for the same reserved inventory', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = reservationBot($fake);
    $pipeline = app(BotTickPipeline::class);

    $pipeline->tick($bot);
    $pipeline->tick($bot->fresh());
    $pipeline->tick($bot->fresh());

    expect(Order::query()->where('bot_id', $bot->id)->where('side', 'sell')->count())->toBe(1)
        ->and($fake->placeCalls)->toHaveCount(2);
});
