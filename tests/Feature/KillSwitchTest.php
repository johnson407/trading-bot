<?php

use App\Actions\ActivateKillSwitch;
use App\Actions\CancelOrder;
use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('pauses every user bot and cancels all open orders', function () {
    $user = User::factory()->create();
    $bots = Bot::factory()->for($user)->running()->count(3)->create();
    $orders = $bots->flatMap(fn (Bot $bot) => Order::factory()->for($bot)->count(2)->create(['status' => 'open']));

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($bots->every(fn (Bot $bot) => $bot->fresh()->status === BotStatus::Paused))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->fresh()->status->value === 'canceled'))->toBeTrue()
        ->and($result->botIds)->toBe($bots->pluck('id')->all())
        ->and($result->canceled())->toBe(6)
        ->and($result->hasFailures())->toBeFalse();
});

it('reports cancellation failures per bot while still pausing immediately', function () {
    $user = User::factory()->create();
    $bots = Bot::factory()->for($user)->running()->count(2)->create();
    $badOrder = Order::factory()->for($bots[0])->create(['status' => 'open']);
    $goodOrder = Order::factory()->for($bots[1])->create(['status' => 'open']);

    $this->mock(CancelOrder::class, function (MockInterface $mock) use ($badOrder) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Order $order) use ($badOrder) {
            if ($order->is($badOrder)) {
                throw new RuntimeException('exchange refused cancellation');
            }

            $order->forceFill([
                'status' => \App\Domain\Order\Enums\OrderStatus::Canceled,
                'closed_at' => now(),
            ])->save();

            return $order;
        });
    });

    $result = app(ActivateKillSwitch::class)->handle($user);

    expect($bots->every(fn (Bot $bot) => $bot->fresh()->status === BotStatus::Paused))->toBeTrue()
        ->and($goodOrder->fresh()->status->value)->toBe('canceled')
        ->and($badOrder->fresh()->status->value)->toBe('open')
        ->and($result->failures())->toBe(1)
        ->and($bots[0]->riskEvents()->where('type', 'risk.kill_switch_cancel_failed')->count())->toBe(1);
});
