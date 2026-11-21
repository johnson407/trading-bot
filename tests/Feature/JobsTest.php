<?php

use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Jobs\PlaceOrder;
use App\Jobs\RunBotTick;
use App\Jobs\SyncOrders;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function jobPaperBot(string $price = '95'): array
{
    $bot = Bot::factory()->running()->create([
        'symbol' => 'BTC/USDT',
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
    ]);

    $fake = new FakeExchangeClient(price: $price);
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
        new PaperExchangeClient($fake),
        $fake,
    ));

    return [$bot, $fake];
}

it('re-delivers RunBotTick without duplicating orders', function () {
    [$bot] = jobPaperBot();

    $job = new RunBotTick($bot->id);
    $job->handle(app(\App\Domain\Bot\BotTickPipeline::class));
    $job->handle(app(\App\Domain\Bot\BotTickPipeline::class));

    expect(Order::where('bot_id', $bot->id)->count())->toBe(1)
        ->and(Fill::where('bot_id', $bot->id)->count())->toBe(0);
});

it('re-delivers PlaceOrder without creating a duplicate order', function () {
    [$bot] = jobPaperBot();
    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create([
        'sequence' => 1,
        'price' => '90',
        'side' => OrderSide::Buy,
    ]);

    $job = new PlaceOrder($bot->id, $grid->id, $level->id, OrderSide::Buy->value, '90', '1');

    $job->handle(app(\App\Domain\Order\OrderPlacementService::class));
    $job->handle(app(\App\Domain\Order\OrderPlacementService::class));

    expect(Order::where('bot_id', $bot->id)->count())->toBe(1);
});

it('re-delivers SyncOrders without duplicating fills or inventory', function () {
    [$bot, $fake] = jobPaperBot();
    $bot->forceFill(['mode' => 'live'])->save();
    config(['trading.live_enabled' => true]);

    $grid = Grid::factory()->for($bot)->create();
    $level = GridLevel::factory()->for($grid)->create([
        'sequence' => 1,
        'price' => '90',
        'side' => OrderSide::Buy,
    ]);
    $order = Order::factory()->for($bot)->create([
        'grid_id' => $grid->id,
        'grid_level_id' => $level->id,
        'exchange_order_id' => 'ex-1',
        'status' => OrderStatus::Open,
        'price' => '90',
        'quantity' => '1',
        'filled_quantity' => '0',
    ]);

    $fake->orders['ex-1'] = ExchangeOrder::make(
        'ex-1',
        $order->client_order_id,
        OrderStatus::Filled,
        OrderSide::Buy,
        '90',
        '1',
        '1',
        '90',
    );

    $job = new SyncOrders($bot->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $bot->refresh();

    expect(Fill::where('bot_id', $bot->id)->count())->toBe(1)
        ->and($bot->base_inventory)->toBe('1.0000000000000000')
        ->and($bot->quote_inventory)->toBe('910.0000000000000000');
});
