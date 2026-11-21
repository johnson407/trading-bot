<?php

use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function paperBotForTick(string $price = '95'): array
{
    $bot = Bot::factory()->running()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'quote_inventory' => '1000',
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

    $fake = new FakeExchangeClient(price: $price);
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('drives a paper bot through a complete tick with fills and inventory', function () {
    [$bot, $fake] = paperBotForTick('95');

    $pipeline = app(BotTickPipeline::class);
    $first = $pipeline->tick($bot);

    expect($first->successful())->toBeTrue()
        ->and($first->ordersPlaced)->toBe(1)
        ->and($bot->fresh()->activeGrid->levels)->toHaveCount(2);

    $fake->price = '89';
    $second = $pipeline->tick($bot->fresh());

    expect($second->successful())->toBeTrue()
        ->and($second->fills)->toBe(1)
        ->and($bot->fresh()->base_inventory)->toBe('1.0000000000000000')
        ->and($bot->fresh()->average_entry_price)->toBe('90.0000000000000000');

    $fake->price = '111';
    $third = $pipeline->tick($bot->fresh());

    expect($third->successful())->toBeTrue()
        ->and($third->fills)->toBe(1)
        ->and($bot->fresh()->base_inventory)->toBe('0.0000000000000000')
        ->and($bot->fresh()->realized_pnl)->toBe('20.0000000000000000')
        ->and($bot->fresh()->last_successful_tick_at)->not->toBeNull();

    expect(Order::where('bot_id', $bot->id)->where('status', 'open')->count())->toBe(1);
});

it('does not re-arm a filled grid level on repeated ticks at the same price', function () {
    [$bot, $fake] = paperBotForTick('95');

    $pipeline = app(BotTickPipeline::class);
    $pipeline->tick($bot);

    $fake->price = '89';
    $second = $pipeline->tick($bot->fresh());

    expect($second->fills)->toBe(1)
        ->and($bot->fresh()->base_inventory)->toBe('1.0000000000000000');

    $orderCount = Order::query()->where('bot_id', $bot->id)->count();
    $fillCount = \App\Models\Fill::query()->where('bot_id', $bot->id)->count();
    $placeCalls = count($fake->placeCalls);

    $third = $pipeline->tick($bot->fresh());

    expect($third->fills)->toBe(0)
        ->and(Order::query()->where('bot_id', $bot->id)->count())->toBe($orderCount)
        ->and(\App\Models\Fill::query()->where('bot_id', $bot->id)->count())->toBe($fillCount)
        ->and(count($fake->placeCalls))->toBe($placeCalls)
        ->and($bot->fresh()->base_inventory)->toBe('1.0000000000000000');
});

it('does not fill orders placed during the same tick', function () {
    [$bot, $fake] = paperBotForTick('89');

    $pipeline = app(BotTickPipeline::class);
    $first = $pipeline->tick($bot);

    expect($first->successful())->toBeTrue()
        ->and($first->ordersPlaced)->toBe(1)
        ->and($first->fills)->toBe(0)
        ->and(Order::query()->where('bot_id', $bot->id)->where('status', 'open')->count())->toBe(1);

    $second = $pipeline->tick($bot->fresh());

    expect($second->fills)->toBe(1)
        ->and($bot->fresh()->base_inventory)->toBe('1.0000000000000000');
});
