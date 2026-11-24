<?php

use App\Actions\ActivateKillSwitch;
use App\Actions\CancelOrder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\FakeCommandPublisher;

uses(RefreshDatabase::class);

it('pauses every user bot and leaves orders to the engine', function () {
    $user = User::factory()->create();
    $bots = Bot::factory()->for($user)->running()->count(3)->create();
    $orders = $bots->flatMap(fn (Bot $bot) => Order::factory()->for($bot)->count(2)->create(['status' => 'open']));

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($bots->every(fn (Bot $bot) => $bot->fresh()->desired_status === BotStatus::Paused))->toBeTrue()
        ->and($bots->every(fn (Bot $bot) => $bot->fresh()->status === BotStatus::Running))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->fresh()->status->value === 'open'))->toBeTrue()
        ->and($result->botIds)->toBe($bots->pluck('id')->all())
        ->and($result->canceled())->toBe(0)
        ->and($result->published)->toBeTrue()
        ->and($result->emergency)->toBeFalse()
        ->and($result->hasFailures())->toBeFalse()
        ->and(app(CommandPublisher::class)->ofType('kill_switch'))->toHaveCount(1)
        ->and(app(CommandPublisher::class)->ofType('kill_switch')[0]['user_id'])->toBe($user->id)
        ->and(app(CommandPublisher::class)->ofType('kill_switch')[0]['bot_id'])->toBeNull();
});

it('cancels live orders when the heartbeat has expired', function () {
    app(EngineHeartbeat::class)->available = false;

    $user = User::factory()->create();
    $bots = Bot::factory()->for($user)->live()->running()->count(2)->create();
    $badOrder = Order::factory()->for($bots[0])->create(['status' => 'open']);
    $goodOrder = Order::factory()->for($bots[1])->create(['status' => 'open']);

    $this->mock(CancelOrder::class, function (MockInterface $mock) use ($badOrder) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Order $order) use ($badOrder) {
            if ($order->is($badOrder)) {
                throw new RuntimeException('exchange refused cancellation');
            }

            $order->forceFill([
                'status' => OrderStatus::Canceled,
                'closed_at' => now(),
            ])->save();

            return $order;
        });
    });

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($bots->every(fn (Bot $bot) => $bot->fresh()->desired_status === BotStatus::Paused))->toBeTrue()
        ->and($goodOrder->fresh()->status->value)->toBe('canceled')
        ->and($badOrder->fresh()->status->value)->toBe('open')
        ->and($result->failures())->toBe(1)
        ->and($result->emergency)->toBeTrue()
        ->and($bots[0]->riskEvents()->where('type', 'risk.kill_switch_cancel_failed')->count())->toBe(1);
});

it('cancels live orders when the command cannot be published', function () {
    /** @var FakeCommandPublisher $publisher */
    $publisher = app(CommandPublisher::class);
    $publisher->fail = true;

    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->live()->running()->create();
    $order = Order::factory()->for($bot)->create(['status' => 'open']);

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Paused)
        ->and($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($result->published)->toBeFalse()
        ->and($result->emergency)->toBeTrue();
});

it('pauses a queued start for a stopped bot', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->for($user)->create([
        'status' => BotStatus::Stopped,
        'desired_status' => BotStatus::Running,
    ]);

    app(ActivateKillSwitch::class)->handle($user);

    expect($bot->fresh()->desired_status)->toBe(BotStatus::Paused)
        ->and($bot->fresh()->status)->toBe(BotStatus::Stopped)
        ->and(app(CommandPublisher::class)->ofType('kill_switch'))->toHaveCount(1);
});
