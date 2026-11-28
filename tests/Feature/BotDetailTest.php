<?php

use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the bot detail with grid, orders, fills, inventory and pnl', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Detail bot',
        'realized_pnl' => '20',
        'base_inventory' => '0',
        'quote_inventory' => '1020',
        'average_entry_price' => '0',
    ]);
    $grid = Grid::factory()->for($bot)->create(['center_price' => '100']);
    GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '90', 'side' => 'buy']);
    GridLevel::factory()->for($grid)->create(['sequence' => 2, 'price' => '110', 'side' => 'sell']);
    Order::factory()->for($bot)->create(['status' => 'open', 'price' => '90']);
    Fill::factory()->for($bot)->create(['price' => '90', 'quantity' => '1']);
    RiskLimit::factory()->forBot($bot)->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Show')
            ->where('bot.name', 'Detail bot')
            ->has('grid.levels', 2)
            ->has('openOrders', 1)
            ->has('recentFills', 1)
            ->where('recentFills.0.price', '90.0000000000000000')
            ->where('inventory.quote', '1020.0000000000000000')
            ->where('pnl.realized', '20.0000000000000000')
            ->has('riskLimits', 2)
            ->etc());
});

it('exposes the open order grid level so the ladder can show an opposite order side', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $grid = Grid::factory()->for($bot)->create(['center_price' => '100']);
    $buyLevel = GridLevel::factory()->for($grid)->create(['sequence' => 1, 'price' => '90', 'side' => 'buy']);
    Order::factory()->for($bot)->create([
        'status' => 'open',
        'side' => 'sell',
        'price' => '90',
        'grid_level_id' => $buyLevel->id,
    ]);

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('openOrders.0.grid_level_id', $buyLevel->id)
            ->where('openOrders.0.side', 'sell')
            ->etc());

    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($show)->toContain('orderForLevel(level)')
        ->and($show)->toContain('orderForLevel(level).side !== level.side');
});

it('links to the grid activity view from the bot detail header', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk();

    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($show)->toContain("route('bots.grid-activity', bot.id)")
        ->and($show)->toContain('Grid activity');
});

it('reflects a new fill on the next poll', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $grid = Grid::factory()->for($bot)->create();
    GridLevel::factory()->for($grid)->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('recentFills', 0)->etc());

    Fill::factory()->for($bot)->create(['price' => '101']);

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentFills', 1)
            ->where('recentFills.0.price', '101.0000000000000000')
            ->etc());
});

it('exposes protective exit rules and an editable card', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->withExitRules()->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Show')
            ->where('bot.stop_loss_percent', '4.0000')
            ->where('bot.take_profit_percent', '9.0000')
            ->where('bot.range_low', '80000.00000000')
            ->where('bot.range_high', '120000.00000000')
            ->etc());

    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($show)->toContain("route('bots.exit-guard.update'")
        ->and($show)->toContain('stop_loss_percent')
        ->and($show)->toContain('take_profit_percent')
        ->and($show)->toContain('range_low')
        ->and($show)->toContain('range_high')
        ->and($show)->toContain('does not sell inventory');
});

it('shows a backtest comparison for a source-linked bot', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    $bot = Bot::factory()->for($user)->fromBacktest($run)->withExitRules()->create([
        'realized_pnl' => '30',
        'fees_paid' => '1',
    ]);
    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'simple-grid',
        'configuration' => $run->config_snapshot['strategy']['configuration'],
    ]);
    Fill::factory()->for($bot)->count(2)->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Show')
            ->where('backtestComparison.source.id', $run->id)
            ->where('backtestComparison.source.net_pnl', '50')
            ->where('backtestComparison.source.return_percent', '5')
            ->where('backtestComparison.paper.net_pnl', '30')
            ->where('backtestComparison.paper.return_percent', '3')
            ->where('backtestComparison.paper.fill_count', 2)
            ->where('backtestComparison.deltas.net_pnl', '-20')
            ->where('backtestComparison.deltas.return_percent_points', '-2')
            ->where('backtestComparison.modified', false)
            ->etc());

    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($show)->toContain('backtestComparison')
        ->and($show)->toContain('Backtest comparison')
        ->and($show)->toContain('Modified since creation')
        ->and($show)->toContain("route('backtests.show'")
        ->and($show)->toContain('comparisonPercent');
});

it('omits the backtest comparison for an unlinked bot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('backtestComparison', null)
            ->etc());
});

it('omits the backtest comparison after the source run is deleted', function () {
    $user = User::factory()->create();
    $run = BacktestRun::factory()->for($user)->completedSource()->create();
    $bot = Bot::factory()->for($user)->fromBacktest($run)->create();
    StrategyConfig::factory()->for($bot)->create();

    $run->delete();
    $bot->refresh();

    $this->actingAs($user)->get(route('bots.show', $bot))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('backtestComparison', null)
            ->etc());
});

it('forbids another user from viewing a bot comparison', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();

    $this->actingAs($other)->get(route('bots.show', $bot))->assertForbidden();
});

it('shares engine publish errors with the bot detail page', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $message = 'The bot was saved, but the engine could not be notified. The engine will retry the reconcile.';
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['engine' => $message]));

    $this->actingAs($user)
        ->withSession(['errors' => $errors])
        ->get(route('bots.show', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.engine', $message)
            ->etc());
});
