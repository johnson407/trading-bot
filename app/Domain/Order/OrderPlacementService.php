<?php

namespace App\Domain\Order;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderPlacementService
{
    /**
     * Request a limit order for a level. Returns the existing order when the
     * deterministic client order id was already used, making placement
     * idempotent under duplicate queue deliveries.
     */
    public function request(
        Bot $bot,
        Grid $grid,
        GridLevel $level,
        OrderSide $side,
        Decimal|string $price,
        Decimal|string $quantity,
        int $cycle = 1,
    ): Order {
        $clientOrderId = ClientOrderId::for($bot, $level, $cycle);
        $price = Decimal::of($price)->toString();
        $quantity = Decimal::of($quantity)->toString();

        return DB::transaction(function () use ($bot, $grid, $level, $side, $price, $quantity, $clientOrderId) {
            $existing = Order::query()
                ->where('bot_id', $bot->id)
                ->where('client_order_id', $clientOrderId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Order) {
                return $existing;
            }

            try {
                return Order::create([
                    'bot_id' => $bot->id,
                    'grid_id' => $grid->id,
                    'grid_level_id' => $level->id,
                    'client_order_id' => $clientOrderId,
                    'mode' => $bot->mode,
                    'side' => $side,
                    'type' => OrderType::Limit,
                    'price' => $price,
                    'quantity' => $quantity,
                    'filled_quantity' => '0',
                    'status' => OrderStatus::Pending,
                    'requested_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    return Order::query()
                        ->where('bot_id', $bot->id)
                        ->where('client_order_id', $clientOrderId)
                        ->firstOrFail();
                }

                throw $exception;
            }
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            || str_contains((string) $exception->getMessage(), 'duplicate key');
    }
}
