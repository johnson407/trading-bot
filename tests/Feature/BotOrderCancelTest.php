<?php

use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

it('queues an order cancellation for the engine without mutating the order', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $this->actingAs($user)
        ->post(route('bots.orders.cancel', [$bot, $order]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $commands = app(CommandPublisher::class)->ofType('order.cancel');

    expect($commands)->toHaveCount(1)
        ->and($commands[0]['user_id'])->toBe($user->id)
        ->and($commands[0]['bot_id'])->toBe($bot->id)
        ->and($commands[0]['payload'])->toBe(['order_id' => $order->id])
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
});

it('does not queue a cancellation for an order owned by another bot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $otherBot = Bot::factory()->for($user)->create();
    $order = Order::factory()->for($otherBot)->create(['status' => OrderStatus::Open]);

    $this->actingAs($user)
        ->post(route('bots.orders.cancel', [$bot, $order]))
        ->assertNotFound();

    expect(app(CommandPublisher::class)->ofType('order.cancel'))->toBeEmpty()
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
});

it('ignores a cancellation for an already terminal order', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Canceled]);

    $this->actingAs($user)
        ->post(route('bots.orders.cancel', [$bot, $order]))
        ->assertRedirect()
        ->assertSessionHas('status', 'The order is already closed.');

    expect(app(CommandPublisher::class)->ofType('order.cancel'))->toBeEmpty();
});

it('cancels a live order through the Laravel exchange path', function () {
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, new FakeExchangeClient));

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->create();
    $order = Order::factory()->for($bot)->create([
        'status' => OrderStatus::Open,
        'mode' => 'live',
    ]);

    $this->actingAs($user)
        ->post(route('bots.orders.cancel', [$bot, $order]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(CommandPublisher::class)->ofType('order.cancel'))->toBeEmpty()
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled);
});

it('denies order cancellation to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $bot = Bot::factory()->for($owner)->create();
    $order = Order::factory()->for($bot)->create(['status' => OrderStatus::Open]);

    $this->actingAs($other)
        ->post(route('bots.orders.cancel', [$bot, $order]))
        ->assertForbidden();

    expect(app(CommandPublisher::class)->ofType('order.cancel'))->toBeEmpty();
});
