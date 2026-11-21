<?php

use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the edit page with the current configuration for an idle bot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => 'idle',
        'name' => 'Editable',
        'symbol' => 'BTC/USDT',
        'exchange' => 'binance',
    ]);

    StrategyConfig::factory()->for($bot)->create([
        'strategy_key' => 'simple-grid',
        'configuration' => [
            'center_price' => '50000',
            'levels_per_side' => 6,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '100',
            'order_quantity' => '0.001',
        ],
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('bots.edit', $bot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Edit')
            ->where('bot.id', $bot->id)
            ->where('bot.name', 'Editable')
            ->where('bot.symbol', 'BTC/USDT')
            ->where('configuration.center_price', '50000')
            ->where('configuration.levels_per_side', 6)
            ->where('editable', true)
            ->etc());
});

it('shows a read-only explanation for a running bot instead of forbidding the page', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['status' => 'running']);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('bots.edit', $bot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bots/Edit')
            ->where('editable', false)
            ->etc());
});

it('links to the edit page from the bot detail page', function () {
    $show = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($show)->toContain("route('bots.edit', bot.id)");
});
