<?php

namespace App\Domain\Bot;

use App\Actions\CancelOrder;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\Bot;
use Throwable;

class CancelBotOrders
{
    public function __construct(
        private readonly CancelOrder $cancelOrder,
        private readonly RiskEventRecorder $events,
    ) {}

    /**
     * Cancel every open order for a bot and report individual failures.
     *
     * @return array{bot_id: int, canceled: int, failed: int, failures: list<array{order_id: int, message: string}>}
     */
    public function handle(
        Bot|int $bot,
        string $failureType = 'risk.kill_switch_cancel_failed',
        string $failureMessage = 'Kill-switch could not cancel an order.',
        RiskSeverity $failureSeverity = RiskSeverity::Warning,
    ): array {

        $bot = $bot instanceof Bot ? $bot : Bot::query()->findOrFail($bot);
        $canceled = 0;
        $failures = [];

        $orders = $bot->orders()
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Open->value,
                OrderStatus::PartiallyFilled->value,
            ])
            ->get();

        foreach ($orders as $order) {
            try {
                $this->cancelOrder->handle($order);
                $canceled++;
            } catch (Throwable $exception) {
                $failures[] = ['order_id' => $order->id, 'message' => $exception->getMessage()];

                $this->events->record(
                    $failureType,
                    $failureMessage,
                    $bot,
                    context: [
                        'order_id' => $order->id,
                        'client_order_id' => $order->client_order_id,
                        'error' => $exception->getMessage(),
                    ],
                    severity: $failureSeverity,
                );
            }
        }

        return [
            'bot_id' => $bot->id,
            'canceled' => $canceled,
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }
}
