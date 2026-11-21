<?php

use App\Domain\Bot\BotReconciler;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Jobs\SyncOrders;
use App\Models\Bot;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function liveSyncBot(FakeExchangeClient $fake): Bot
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

function runOrderSync(Bot $bot): void
{
    app()->call([new SyncOrders($bot->id), 'handle']);
}

it('applies a live fill through the strategy and places the opposite order', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = liveSyncBot($fake);

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $sell = Order::query()->where('side', 'sell')->firstOrFail();

    $fake->fill($buy->exchange_order_id, '90', '1');
    runOrderSync($bot);

    $buy->refresh();
    $config = StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail();
    $state = $config->state;

    expect($buy->status->value)->toBe('filled')
        ->and($buy->filled_quantity)->toBe('1.0000000000000000')
        ->and($bot->fresh()->base_inventory)->toBe('2.0000000000000000')
        ->and($state['data']['disarmed_levels'] ?? [])->toHaveKey('90')
        ->and(Order::query()->where('side', 'buy')->where('status', 'open')->count())->toBe(0);

    app(BotTickPipeline::class)->tick($bot->fresh());

    expect($fake->placeCalls)->toHaveCount(2);

    $fake->fill($sell->exchange_order_id, '110', '1');
    runOrderSync($bot);

    $bot->refresh();

    expect($bot->base_inventory)->toBe('1.0000000000000000')
        ->and($bot->realized_pnl)->toBe('15.0000000000000000')
        ->and($fake->placeCalls)->toHaveCount(3)
        ->and(Order::query()->where('side', 'buy')->where('status', 'open')->where('price', '90')->count())->toBe(1);
});

it('applies partial live fills in reconciliation without marking the order filled', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = liveSyncBot($fake);

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '0.4');
    $fake->quoteBalance = '964';
    $fake->baseBalance = '0.4';

    $result = app(BotReconciler::class)->reconcile($bot);

    $buy->refresh();

    expect($result->ok())->toBeTrue()
        ->and($buy->status->value)->toBe('partially_filled')
        ->and($buy->filled_quantity)->toBe('0.4000000000000000')
        ->and($bot->fresh()->base_inventory)->toBe('1.4000000000000000')
        ->and(StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail()->state['data']['disarmed_levels'] ?? [])->toHaveKey('90');
});

it('adopts an accepted pending order by client id without placing a replacement', function () {
    $fake = new FakeExchangeClient(price: '95');
    $fake->timeoutAfterAccept = true;
    $bot = liveSyncBot($fake);

    app(BotTickPipeline::class)->tick($bot);

    expect(Order::query()->whereNull('exchange_order_id')->count())->toBe(2);

    $fake->timeoutAfterAccept = false;
    runOrderSync($bot);

    expect(Order::query()->whereNotNull('exchange_order_id')->count())->toBe(2)
        ->and(Order::query()->where('status', 'open')->count())->toBe(2)
        ->and($fake->placeCalls)->toHaveCount(2);
});

it('skips order sync when live trading is disabled', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = liveSyncBot($fake);

    app(BotTickPipeline::class)->tick($bot);
    config(['trading.live_enabled' => false]);

    runOrderSync($bot);

    expect($fake->openOrdersCalls)->toBe(0);
});

it('dispatches order sync jobs only for running live bots', function () {
    Queue::fake();

    $live = Bot::factory()->running()->live()->create();
    Bot::factory()->running()->create();
    Bot::factory()->live()->create(['status' => BotStatus::Paused]);

    $this->artisan('bots:sync-orders')->assertSuccessful();

    Queue::assertPushed(SyncOrders::class, 1);
    Queue::assertPushed(SyncOrders::class, fn (SyncOrders $job) => $job->botId === $live->id);
});
