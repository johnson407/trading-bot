<?php

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\InvalidOrderTransitionException;
use App\Domain\Order\OrderStateMachine;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies every legal order transition and stamps timestamps', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $machine = app(OrderStateMachine::class);

    $machine->transition($order, OrderStatus::Open);
    expect($order->status)->toBe(OrderStatus::Open)->and($order->opened_at)->not->toBeNull();

    $machine->transition($order, OrderStatus::PartiallyFilled);
    $machine->transition($order, OrderStatus::Filled);

    expect($order->status)->toBe(OrderStatus::Filled)->and($order->closed_at)->not->toBeNull();

    $canceled = Order::factory()->create(['status' => OrderStatus::Open]);
    $machine->transition($canceled, OrderStatus::Canceled);
    expect($canceled->status)->toBe(OrderStatus::Canceled);
});

it('rejects illegal order transitions', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Filled]);

    expect(fn () => app(OrderStateMachine::class)->transition($order, OrderStatus::Open))
        ->toThrow(InvalidOrderTransitionException::class);
});
