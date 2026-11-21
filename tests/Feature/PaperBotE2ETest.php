<?php

use App\Actions\StartBot;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('creates a paper bot over HTTP, simulates fills and displays pnl', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('bots.store'), [
        'name' => 'Docker paper E2E',
        'exchange' => 'binance',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'paper',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '100',
        'level_count' => 1,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '10',
        'order_quantity' => '1',
    ])->assertRedirect(route('bots.index'));

    $bot = Bot::query()->where('user_id', $user->id)->firstOrFail();

    app(StartBot::class)->handle($bot);
    $bot->refresh();

    expect($bot->status)->toBe(BotStatus::Running);

    $fake = new FakeExchangeClient(price: '95');
    app()->instance(
        ExchangeClientFactory::class,
        new ExchangeClientFactory(new PaperExchangeClient($fake), $fake),
    );

    $pipeline = app(BotTickPipeline::class);

    expect($pipeline->tick($bot->fresh())->successful())->toBeTrue();

    $fake->price = '89';
    expect($pipeline->tick($bot->fresh())->successful())->toBeTrue();

    $fake->price = '111';
    expect($pipeline->tick($bot->fresh())->successful())->toBeTrue();

    $bot->refresh();

    expect($bot->base_inventory)->toBe('0.0000000000000000')
        ->and($bot->realized_pnl)->toBe('20.0000000000000000')
        ->and($bot->quote_inventory)->toBe('1020.0000000000000000')
        ->and(Fill::query()->where('bot_id', $bot->id)->count())->toBe(2)
        ->and($bot->last_successful_tick_at)->not->toBeNull();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Bots/Show')
            ->where('bot.name', 'Docker paper E2E')
            ->where('bot.status', 'running')
            ->where('pnl.realized', '20.0000000000000000')
            ->has('recentFills', 2)
            ->has('openOrders', 1)
            ->etc());
});
