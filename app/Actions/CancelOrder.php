<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\OrderStateMachine;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Exchange\ExchangeClientFactory;

class CancelOrder
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly ActivityRecorder $activity,
        private readonly ExchangeClientFactory $clients,
    ) {}

    public function handle(Order $order): Order
    {
        if ($order->status->isTerminal()) {
            return $order;
        }

        /** @var Bot $bot */
        $bot = $order->bot;

        $hasExchangeID = $order->exchange_order_id !== null && $order->exchange_order_id !== '';
        $hasClientID = $order->client_order_id !== null && $order->client_order_id !== '';

        if ($bot->isLive() && ($hasExchangeID || $hasClientID)) {
            try {
                $this->clients
                    ->forBot($bot)
                    ->cancelOrder(
                        $bot,
                        $bot->symbol,
                        $hasExchangeID ? $order->exchange_order_id : null,
                        $hasClientID ? $order->client_order_id : null,
                    );
            } catch (ExchangeException $exception) {
                if ($exception->type !== ExchangeErrorType::NotFound) {
                    $this->activity->recordUser(
                        'order.local_cancel_failed',
                        "Order {$order->client_order_id} could not be canceled on the exchange.",
                        $bot,
                        context: [
                            'order_id' => $order->id,
                            'client_order_id' => $order->client_order_id,
                            'exchange_order_id' => $order->exchange_order_id,
                            'error' => $exception->getMessage(),
                        ],
                        severity: 'error',
                    );

                    throw $exception;
                }
            }
        }

        $canceled = $this->stateMachine->transition($order, OrderStatus::Canceled);

        $this->activity->recordUser(
            'order.local_canceled',
            "Order {$order->client_order_id} canceled",
            $bot,
            context: [
                'order_id' => $order->id,
                'client_order_id' => $order->client_order_id,
                'exchange_order_id' => $order->exchange_order_id,
                'price' => $order->price,
                'quantity' => $order->quantity,
            ],
        );

        return $canceled;
    }
}
