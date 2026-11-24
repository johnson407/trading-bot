<?php

namespace App\Actions;

use App\Models\Bot;
use App\Models\Order;
use App\Services\Engine\CommandPublisher;
use InvalidArgumentException;

class CancelBotOrder
{
    public function __construct(
        private readonly CommandPublisher $commands,
        private readonly CancelOrder $cancelOrder,
    ) {}

    public function handle(Bot $bot, Order $order): bool
    {
        if ($order->bot_id !== $bot->id) {
            throw new InvalidArgumentException('The selected order does not belong to this bot.');
        }

        if ($order->status->isTerminal()) {
            return false;
        }

        // The Go engine cannot place or cancel live exchange orders yet. Route
        // a user cancellation through the retained Laravel exchange path.
        if ($bot->isLive()) {
            $this->cancelOrder->handle($order);

            return true;
        }

        $this->commands->publish('order.cancel', $bot->user_id, $bot->id, [
            'order_id' => $order->id,
        ]);

        return true;
    }
}
