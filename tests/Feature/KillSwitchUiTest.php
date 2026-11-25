<?php

use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use App\Services\Engine\EngineHeartbeat;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\FakeExchangeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(null, new FakeExchangeClient));
});

it('renders the kill-switch confirmation with account counts', function () {
    $user = User::factory()->create();
    $running = Bot::factory()->for($user)->running()->create();
    $stopped = Bot::factory()->for($user)->create(['status' => 'stopped']);
    Order::factory()->for($running)->create(['status' => 'open']);
    Order::factory()->for($stopped)->create(['status' => 'open']);

    $this->actingAs($user)->get(route('kill-switch.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('KillSwitch/Confirm')
            ->where('botCount', 2)
            ->where('activeBotCount', 1)
            ->where('openOrderCount', 2)
            ->etc());
});

it('requires the exact kill-switch phrase', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->running()->create();

    $this->actingAs($user)->post(route('kill-switch.store'), ['confirmation' => 'kill'])
        ->assertSessionHasErrors('confirmation');

    expect($bot->fresh()->status->value)->toBe('running');
});

it('pauses every bot and queues the engine kill-switch when confirmed', function () {
    $user = User::factory()->create();
    $first = Bot::factory()->for($user)->running()->create();
    $second = Bot::factory()->for($user)->running()->create();
    $order = Order::factory()->for($first)->create(['status' => 'open']);

    $this->actingAs($user)->post(route('kill-switch.store'), ['confirmation' => 'KILL SWITCH'])
        ->assertRedirect(route('dashboard'));

    expect($first->fresh()->desired_status->value)->toBe('paused')
        ->and($second->fresh()->desired_status->value)->toBe('paused')
        ->and($first->fresh()->status->value)->toBe('running')
        ->and($order->fresh()->status->value)->toBe('open');
});

it('cancels live orders when the engine heartbeat is unavailable', function () {
    app(EngineHeartbeat::class)->available = false;

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => 'open']);

    $this->actingAs($user)->post(route('kill-switch.store'), ['confirmation' => 'KILL SWITCH'])
        ->assertRedirect(route('dashboard'));

    expect($bot->fresh()->desired_status->value)->toBe('paused')
        ->and($order->fresh()->status->value)->toBe('canceled');
});
