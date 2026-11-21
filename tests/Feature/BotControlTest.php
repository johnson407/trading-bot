<?php

use App\Actions\StartBot;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('starts, pauses and stops a paper bot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => BotStatus::Idle,
        'mode' => 'paper',
    ]);
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $this->actingAs($user)->post(route('bots.start', $bot))->assertRedirect();
    expect($bot->fresh()->status)->toBe(BotStatus::Running);

    $this->actingAs($user)->post(route('bots.pause', $bot))->assertRedirect();
    expect($bot->fresh()->status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);

    $this->actingAs($user)->post(route('bots.stop', $bot))->assertRedirect();
    expect($bot->fresh()->status)->toBe(BotStatus::Stopped)
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled);
});

it('requires the exact bot name to start a live bot', function () {
    config(['trading.live_enabled' => true]);

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create([
        'name' => 'Live BTC grid',
        'status' => BotStatus::Idle,
        'capital_allocation' => '1000',
    ]);

    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(live: new FakeExchangeClient(quoteBalance: '1000')));

    $this->actingAs($user)->post(route('bots.start', $bot))
        ->assertSessionHasErrors('confirmation');
    expect($bot->fresh()->status)->toBe(BotStatus::Idle);

    $this->actingAs($user)->post(route('bots.start', $bot), ['confirmation' => 'wrong'])
        ->assertSessionHasErrors('confirmation');
    expect($bot->fresh()->status)->toBe(BotStatus::Idle);

    $this->actingAs($user)->post(route('bots.start', $bot), ['confirmation' => 'Live BTC grid on binance'])
        ->assertSessionHasNoErrors();
    expect($bot->fresh()->status)->toBe(BotStatus::Running);
});

it('preserves the previous state when a control action fails', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create(['status' => BotStatus::Idle]);

    $this->mock(StartBot::class, function ($mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
    });

    $this->actingAs($user)->post(route('bots.start', $bot))
        ->assertRedirect()
        ->assertSessionHasErrors('action');

    expect($bot->fresh()->status)->toBe(BotStatus::Idle);
});

it('denies control actions to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create(['status' => BotStatus::Idle]);

    $this->actingAs($other)->post(route('bots.start', $bot))->assertForbidden();
    expect($bot->fresh()->status)->toBe(BotStatus::Idle);
});
