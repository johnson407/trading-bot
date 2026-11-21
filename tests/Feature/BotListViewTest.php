<?php

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows each bot exchange and unrealized profit and loss in the list', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'exchange' => 'kraken',
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '120'],
    ]);

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Index')
            ->where('bots.0.exchange', 'kraken')
            ->where('bots.0.unrealized_pnl', '20')
            ->etc());
});

it('reports zero unrealized profit and loss when the bot has no position', function () {
    $user = User::factory()->create();
    Bot::factory()->for($user)->create([
        'base_inventory' => '0',
        'average_entry_price' => '0',
    ]);

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bots.0.unrealized_pnl', '0')
            ->etc());
});

it('renders exchange and unrealized columns and links the detail activity log', function () {
    $index = file_get_contents(resource_path('js/Pages/Bots/Index.vue'));
    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($index)->toContain('>Exchange<')
        ->and($index)->toContain('Unrealized P&amp;L')
        ->and($index)->toContain('bot.unrealized_pnl')
        ->and($show)->toContain("route('bots.activity', bot.id)");
});

it('shows the same unrealized profit and loss on the list and detail pages', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'base_inventory' => '1',
        'average_entry_price' => '100',
        'settings' => ['mark_price' => '120'],
    ]);

    $this->actingAs($user)
        ->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page->where('bots.0.unrealized_pnl', '20')->etc());

    $this->actingAs($user)
        ->get(route('bots.show', $bot))
        ->assertInertia(fn (Assert $page) => $page->where('pnl.unrealized', '20')->etc());
});
