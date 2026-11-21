<?php

use App\Domain\Bot\BotReconciler;
use App\Domain\Bot\BotTickPipeline;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function feeBot(FakeExchangeClient $fake, string $feeRate = '0'): Bot
{
    config(['trading.live_enabled' => true]);

    $bot = Bot::factory()->running()->live()->create([
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'quote_inventory' => '1000',
        'fee_rate' => $feeRate,
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

function runFeeSync(Bot $bot): void
{
    app()->call([new App\Jobs\SyncOrders($bot->id), 'handle']);
}

it('records the exchange reported fee on a synced fill', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '0.09');
    runFeeSync($bot);

    $fill = Fill::query()->firstOrFail();
    $bot->refresh();

    expect($fill->fee)->toBe('0.0900000000000000')
        ->and($bot->fees_paid)->toBe('0.0900000000000000')
        ->and($bot->realized_pnl)->toBe('-0.0900000000000000')
        ->and($bot->quote_inventory)->toBe('909.9100000000000000');
});

it('falls back to the bot fee rate when the exchange reports no fee', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0.001');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1');
    runFeeSync($bot);

    $fill = Fill::query()->firstOrFail();

    expect($fill->fee)->toBe('0.0900000000000000')
        ->and($fill->fee_currency)->toBe('USDT')
        ->and($bot->fresh()->fees_paid)->toBe('0.0900000000000000');
});

it('uses the exchange fee when reconciling a fill', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '0.09');
    $fake->quoteBalance = '909.91';
    $fake->baseBalance = '1';

    app(BotReconciler::class)->reconcile($bot);

    $fill = Fill::query()->firstOrFail();

    expect($fill->fee)->toBe('0.0900000000000000')
        ->and($bot->fresh()->realized_pnl)->toBe('-0.0900000000000000');
});

it('records the cumulative exchange fee once across partial fills', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();

    // The first partial fill arrives without commission.
    $fake->fill($buy->exchange_order_id, '90', '0.4');
    runFeeSync($bot);

    expect(Fill::query()->sum('fee'))->toBe('0.0000000000000000');

    // The final order carries the cumulative commission.
    $fake->fill($buy->exchange_order_id, '90', '1', '0.09');
    runFeeSync($bot);

    expect(Fill::query()->count())->toBe(2)
        ->and(Fill::query()->orderByDesc('id')->first()->fee)->toBe('0.0900000000000000')
        ->and(\App\Domain\Support\Decimal::of($bot->fresh()->fees_paid)->toString())->toBe('0.09');
});

it('deducts a base-asset fee from received inventory and values it in quote', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '0.001', 'BTC');
    runFeeSync($bot);

    $bot->refresh();

    expect($bot->base_inventory)->toBe('0.9990000000000000')
        ->and($bot->quote_inventory)->toBe('910.0000000000000000')
        ->and($bot->realized_pnl)->toBe('-0.0900000000000000')
        ->and($bot->fees_paid)->toBe('0.0900000000000000');
});

it('values a third-asset fee at the fill price without touching quote inventory', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '0.001', 'BNB');
    runFeeSync($bot);

    $bot->refresh();

    expect($bot->base_inventory)->toBe('1.0000000000000000')
        ->and($bot->quote_inventory)->toBe('910.0000000000000000')
        ->and($bot->fees_paid)->toBe('0.0900000000000000');
});

it('keeps an explicit zero exchange fee instead of falling back to the bot rate', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0.001');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '0');
    runFeeSync($bot);

    $fill = Fill::query()->firstOrFail();

    expect($fill->fee)->toBe('0.0000000000000000')
        ->and($bot->fresh()->fees_paid)->toBe('0.0000000000000000');
});

it('credits a negative exchange fee rebate to realized profit', function () {
    $fake = new FakeExchangeClient(price: '95');
    $bot = feeBot($fake, '0');

    app(BotTickPipeline::class)->tick($bot);

    $buy = Order::query()->where('side', 'buy')->firstOrFail();
    $fake->fill($buy->exchange_order_id, '90', '1', '-0.01');
    runFeeSync($bot);

    $fill = Fill::query()->firstOrFail();

    expect($fill->fee)->toBe('-0.0100000000000000')
        ->and($bot->fresh()->realized_pnl)->toBe('0.0100000000000000')
        ->and($bot->fresh()->fees_paid)->toBe('-0.0100000000000000');
});
