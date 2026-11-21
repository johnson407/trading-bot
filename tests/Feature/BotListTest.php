<?php

use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('returns bot summaries with state, mode, pnl and open order counts', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'name' => 'Summary bot',
        'realized_pnl' => '12.5',
        'mode' => 'paper',
        'status' => 'running',
    ]);
    Order::factory()->for($bot)->create(['status' => 'open']);
    Order::factory()->for($bot)->create(['status' => 'filled']);

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Index')
            ->has('bots', 1)
            ->where('bots.0.name', 'Summary bot')
            ->where('bots.0.mode', 'paper')
            ->where('bots.0.status', 'running')
            ->where('bots.0.realized_pnl', '12.5000000000000000')
            ->where('bots.0.open_orders_count', 1)
            ->etc()
        );
});

it('does not expose another users bots', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Bot::factory()->for($owner)->create(['name' => 'Owner bot']);
    Bot::factory()->for($other)->create(['name' => 'Other bot']);

    $this->actingAs($other)
        ->get(route('bots.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots', 1)
            ->where('bots.0.name', 'Other bot')
            ->etc()
        );
});
