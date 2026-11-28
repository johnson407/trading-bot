<?php

use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\Order;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function gridActivityBot(): Bot
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);

    return $bot;
}

function bindGridActivityExchange(?FakeExchangeClient $fake = null): FakeExchangeClient
{
    $fake ??= new FakeExchangeClient;
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(overrides: ['binance' => $fake]));

    return $fake;
}

it('renders the owner grid activity payload', function () {
    $bot = gridActivityBot();
    $fake = bindGridActivityExchange();
    Fill::factory()->create(['bot_id' => $bot->id, 'filled_at' => now()->subMinute()]);
    Order::factory()->create([
        'bot_id' => $bot->id,
        'status' => OrderStatus::Canceled,
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($bot->user)
        ->get(route('bots.grid-activity', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/GridActivity')
            ->where('bot.id', $bot->id)
            ->where('gridActivity.range', '24h')
            ->where('gridActivity.available_ranges', ['1h', '6h', '24h', '7d'])
            ->where('gridActivity.price.available', true)
            ->where('gridActivity.summary.fill_count', 1)
            ->where('gridActivity.summary.cancel_count', 1)
            ->where('gridActivity.summary.cancel_causes', 'all')
            ->where('gridActivity.truncated.fills', false));
    expect($fake->candleCalls)->toHaveCount(1);
});

it('forbids another user from viewing grid activity', function () {
    $bot = gridActivityBot();
    bindGridActivityExchange();

    $this->actingAs(User::factory()->create())
        ->get(route('bots.grid-activity', $bot))
        ->assertForbidden();
});

it('maps every configured range to its timeframe and window keys', function () {
    $bot = gridActivityBot();
    bindGridActivityExchange();

    $cases = ['1h' => '1m', '6h' => '5m', '24h' => '5m', '7d' => '1h'];

    foreach ($cases as $range => $timeframe) {
        $this->actingAs($bot->user)
            ->get(route('bots.grid-activity', ['bot' => $bot, 'range' => $range]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('gridActivity.range', $range)
                ->where('gridActivity.price.timeframe', $timeframe)
                ->has('gridActivity.window_start')
                ->has('gridActivity.window_end'));
    }
});

it('falls back to the default range for unknown values', function () {
    $bot = gridActivityBot();
    bindGridActivityExchange();

    $this->actingAs($bot->user)
        ->get(route('bots.grid-activity', ['bot' => $bot, 'range' => 'bogus']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('gridActivity.range', '24h'));
});

it('degrades without a price line when public candles fail', function () {
    $bot = gridActivityBot();
    $fake = bindGridActivityExchange();
    $fake->candleFailure = new RuntimeException('exchange down');

    $this->actingAs($bot->user)
        ->get(route('bots.grid-activity', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gridActivity.price.available', false)
            ->where('gridActivity.price.error', 'Price history is unavailable.')
            ->where('gridActivity.summary.fill_count', 0));
});

it('keeps candles available when the window has no activity', function () {
    $bot = gridActivityBot();
    bindGridActivityExchange();

    $this->actingAs($bot->user)
        ->get(route('bots.grid-activity', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gridActivity.price.available', true)
            ->where('gridActivity.grids', [])
            ->where('gridActivity.fills', [])
            ->where('gridActivity.cancels', []));
});

it('reports truncation and static spacing in the payload', function () {
    config(['trading.grid_activity.max_markers' => 1]);
    $bot = gridActivityBot();
    $bot->update(['strategy_key' => 'simple-grid']);
    $bot->strategyConfig()->create([
        'strategy_key' => 'simple-grid',
        'configuration' => ['spacing_type' => 'geometric', 'spacing_value' => '0.004'],
    ]);
    bindGridActivityExchange();
    Fill::factory()->count(2)->create(['bot_id' => $bot->id, 'filled_at' => now()->subMinutes(5)]);
    Grid::factory()->create([
        'bot_id' => $bot->id,
        'status' => GridStatus::Active,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now(),
    ]);

    $this->actingAs($bot->user)
        ->get(route('bots.grid-activity', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('gridActivity.truncated.fills', true)
            ->where('gridActivity.summary.fill_count', 2)
            ->where('gridActivity.summary.spacing_type', 'geometric')
            ->where('gridActivity.summary.spacing', '0.004')
            ->where('gridActivity.summary.spacing_hysteresis_percent', null));
});
