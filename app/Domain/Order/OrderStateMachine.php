<?php

namespace App\Domain\Order;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;

class OrderStateMachine
{
    /**
     * Apply a validated state transition and persist it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(Order $order, OrderStatus $to, array $attributes = []): Order
    {
        if ($order->status === $to) {
            return $order;
        }

        if (! $order->status->canTransitionTo($to)) {
            throw InvalidOrderTransitionException::from($order->status, $to);
        }

        $attributes['status'] = $to;

        if ($to === OrderStatus::Open && $order->opened_at === null) {
            $attributes['opened_at'] = now();
        }

        if ($to->isTerminal()) {
            $attributes['closed_at'] = now();
        }

        $order->forceFill($attributes)->save();

        return $order->refresh();
    }
}
