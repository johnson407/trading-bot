<?php

use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
