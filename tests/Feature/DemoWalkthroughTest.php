<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('walks the primary dashboard surface end to end', function () {
    config(['trading.exchanges' => ['binance', 'kraken']]);

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->has('summary')->etc());

    $this->actingAs($user)->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Bots/Index')->etc());

    $this->actingAs($user)->get(route('bots.create'))
        ->assertInertia(fn (Assert $page) => $page->component('Bots/Create')->where('exchanges', ['binance', 'kraken'])->etc());

    $this->actingAs($user)->get(route('exchange-credentials.index'))
        ->assertInertia(fn (Assert $page) => $page->component('ExchangeCredentials/Index')->etc());

    $this->actingAs($user)->get(route('kill-switch.create'))
        ->assertInertia(fn (Assert $page) => $page->component('KillSwitch/Confirm')->etc());

    $this->actingAs($user)->post(route('bots.store'), [
        'name' => 'Kraken demo',
        'exchange' => 'kraken',
        'symbol' => 'BTC/USDT',
        'strategy_key' => 'simple-grid',
        'mode' => 'paper',
        'capital_allocation' => '1000',
        'fee_rate' => '0',
        'center_price' => '50000',
        'level_count' => 4,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ])->assertRedirect(route('bots.index'));

    $bot = Bot::query()->firstOrFail();

    $this->actingAs($user)->get(route('bots.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.status', 'Bot Kraken demo created.')
            ->where('bots.0.exchange', 'kraken')
            ->etc());

    $this->actingAs($user)->get(route('bots.show', $bot))
        ->assertInertia(fn (Assert $page) => $page->component('Bots/Show')->where('bot.exchange', 'kraken')->etc());

    $this->actingAs($user)->get(route('bots.edit', $bot))
        ->assertInertia(fn (Assert $page) => $page->component('Bots/Edit')->where('bot.id', $bot->id)->etc());

    $this->actingAs($user)->patch(route('bots.update', $bot), [
        'name' => 'Kraken demo revised',
        'capital_allocation' => '1500',
        'fee_rate' => '0.001',
        'center_price' => '51000',
        'level_count' => 5,
        'spacing_type' => 'arithmetic',
        'spacing_value' => '100',
        'order_quantity' => '0.001',
    ])->assertRedirect(route('bots.show', $bot));

    expect($bot->fresh()->name)->toBe('Kraken demo revised')
        ->and(StrategyConfig::query()->where('bot_id', $bot->id)->firstOrFail()->configuration['levels_per_side'])->toBe(5);
});

it('surfaces a live confirmation error through the control flow', function () {
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);
    $bot = Bot::factory()->for($user)->live()->create([
        'name' => 'Live demo',
        'status' => 'idle',
        'capital_allocation' => '1000',
    ]);

    $this->from(route('bots.show', $bot))
        ->actingAs($user)
        ->post(route('bots.start', $bot), ['confirmation' => 'Live demo'])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($user)->get(route('bots.show', $bot))
        ->assertInertia(fn (Assert $page) => $page->where('errors.confirmation', fn ($v) => is_string($v))->etc());
});
