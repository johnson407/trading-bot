<?php

use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function livePlacementBot(FakeExchangeClient $fake): Bot
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

it('keeps an ambiguously placed order pending and never places a replacement', function () {
    $fake = new FakeExchangeClient(price: '95');
    $fake->timeoutAfterAccept = true;
    $bot = livePlacementBot($fake);

    $first = app(BotTickPipeline::class)->tick($bot);

    expect($first->successful())->toBeTrue()
        ->and($fake->placeCalls)->toHaveCount(2)
        ->and($fake->orders)->toHaveCount(2)
        ->and(Order::query()->count())->toBe(2)
        ->and(Order::query()->where('status', 'pending')->count())->toBe(2)
        ->and(Order::query()->whereNotNull('last_error')->count())->toBe(2);

    $fake->timeoutAfterAccept = false;
    $second = app(BotTickPipeline::class)->tick($bot->fresh());

    expect($second->successful())->toBeTrue()
        ->and($fake->placeCalls)->toHaveCount(2)
        ->and($fake->orders)->toHaveCount(2)
        ->and(Order::query()->count())->toBe(2)
        ->and(Order::query()->where('status', 'pending')->count())->toBe(2);
});
