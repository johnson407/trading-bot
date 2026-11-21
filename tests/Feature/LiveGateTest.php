<?php

use App\Domain\Bot\BotReconciler;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\BalanceSynchronizer;
use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

function liveBotForTick(): array
{
    $bot = Bot::factory()->running()->live()->create([
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

    $fake = new FakeExchangeClient(price: '95');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    return [$bot, $fake];
}

it('pauses a live bot instead of trading when live mode is disabled', function () {
    config(['trading.live_enabled' => false]);

    [$bot, $fake] = liveBotForTick();

    $result = app(BotTickPipeline::class)->tick($bot);

    $bot->refresh();

    expect($result->status)->toBe('skipped')
        ->and($bot->status)->toBe(BotStatus::Paused)
        ->and($bot->last_error)->toContain('Live trading is disabled')
        ->and($fake->placeCalls)->toBe([]);
});

it('places live orders when live mode is enabled', function () {
    config(['trading.live_enabled' => true]);

    [$bot, $fake] = liveBotForTick();

    $result = app(BotTickPipeline::class)->tick($bot);

    expect($result->successful())->toBeTrue()
        ->and($result->ordersPlaced)->toBe(2)
        ->and($fake->placeCalls)->toHaveCount(2)
        ->and($bot->fresh()->mode)->toBe(BotMode::Live)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running);
});

it('skips reconciliation for a live bot when live mode is disabled', function () {
    config(['trading.live_enabled' => false]);

    [$bot, $fake] = liveBotForTick();

    $result = app(BotReconciler::class)->reconcile($bot);

    expect($result->status)->toBe('skipped')
        ->and($fake->openOrdersCalls)->toBe(0)
        ->and($fake->balanceCalls)->toBe(0)
        ->and($bot->fresh()->status)->toBe(BotStatus::Running);
});

it('skips balance synchronisation for a live bot when live mode is disabled', function () {
    config(['trading.live_enabled' => false]);

    [$bot, $fake] = liveBotForTick();
    $bot->forceFill(['base_inventory' => '2', 'quote_inventory' => '500'])->save();

    $balances = app(BalanceSynchronizer::class)->sync($bot);

    expect($balances)->toBeNull()
        ->and($fake->balanceCalls)->toBe(0)
        ->and($bot->fresh()->quote_inventory)->toBe('500.0000000000000000');
});
