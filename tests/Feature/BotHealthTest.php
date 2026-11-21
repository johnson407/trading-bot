<?php

use App\Domain\Bot\BalanceSynchronizer;
use App\Domain\Bot\BotHealthMonitor;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('pauses a running bot whose last successful tick is stale', function () {
    $bot = Bot::factory()->running()->create([
        'last_tick_at' => now()->subMinute(),
        'last_successful_tick_at' => now()->subMinute(),
    ]);

    $stale = app(BotHealthMonitor::class)->check();

    expect($stale)->toHaveCount(1)
        ->and($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and(ActivityLog::where('bot_id', $bot->id)->where('type', 'bot.stale')->exists())->toBeTrue();
});

it('leaves a healthy running bot alone', function () {
    $bot = Bot::factory()->running()->create([
        'last_tick_at' => now(),
        'last_successful_tick_at' => now(),
    ]);

    $stale = app(BotHealthMonitor::class)->check();

    expect($stale)->toBeEmpty()
        ->and($bot->fresh()->status)->toBe(BotStatus::Running)
        ->and(ActivityLog::where('bot_id', $bot->id)->count())->toBe(0);
});

it('refreshes live base and quote balances', function () {
    config(['trading.live_enabled' => true]);

    $bot = Bot::factory()->live()->create([
        'quote_inventory' => '1000',
        'base_inventory' => '0',
    ]);
    $fake = new FakeExchangeClient(quoteBalance: '1200', baseBalance: '2');
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    $balances = app(BalanceSynchronizer::class)->sync($bot);

    expect($balances)->toBe(['base' => '2', 'quote' => '1200'])
        ->and($bot->fresh()->base_inventory)->toBe('2.0000000000000000')
        ->and($bot->fresh()->quote_inventory)->toBe('1200.0000000000000000')
        ->and(ActivityLog::where('bot_id', $bot->id)->where('type', 'bot.balance_synced')->exists())->toBeTrue();
});

it('does not touch paper balances', function () {
    $bot = Bot::factory()->create(['quote_inventory' => '1000']);
    $fake = new FakeExchangeClient(quoteBalance: '1200');

    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory($fake, $fake));

    expect(app(BalanceSynchronizer::class)->sync($bot))->toBeNull()
        ->and($bot->fresh()->quote_inventory)->toBe('1000.0000000000000000');
});

it('runs the health and balance commands', function () {
    $this->artisan('bots:health')->assertSuccessful();
    $this->artisan('bots:sync-balances')->assertSuccessful();
});
