<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Bot;
use Throwable;

class StopBot
{
    public function __construct(
        private readonly CancelOrder $cancelOrder,
        private readonly ActivityRecorder $activity,
    ) {}

    public function handle(Bot $bot): Bot
    {
        if ($bot->status === BotStatus::Stopped) {
            return $bot;
        }

        $failures = [];

        $openOrders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->get();

        foreach ($openOrders as $order) {
            try {
                $this->cancelOrder->handle($order);
            } catch (Throwable $e) {
                $failures[] = $order->client_order_id;
            }
        }

        $bot->update([
            'status' => BotStatus::Stopped,
            'last_error' => $failures === []
                ? null
                : 'Failed to cancel orders: '.implode(', ', $failures),
        ]);

        $this->activity->record(
            'bot.stopped',
            'Bot stopped',
            $bot,
            context: [
                'canceled_orders' => $openOrders->count() - count($failures),
                'failed_orders' => $failures,
            ],
            severity: $failures === [] ? 'info' : 'error',
        );

        return $bot->refresh();
    }
}
