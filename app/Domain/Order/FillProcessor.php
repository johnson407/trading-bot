<?php

namespace App\Domain\Order;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\FillData;
use App\Domain\Order\ValueObjects\FillResult;
use App\Domain\Portfolio\Exceptions\NegativeInventoryException;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FillProcessor
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly ActivityRecorder $activity,
        private readonly InventoryLedger $ledger,
    ) {
    }

    public function process(Bot $bot, Order $order, FillData $fillData): FillResult
    {
        try {
            return DB::transaction(function () use ($bot, $order, $fillData) {
            $lockedBot = Bot::query()->whereKey($bot->id)->lockForUpdate()->firstOrFail();
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->bot_id !== $lockedBot->id) {
                throw new RuntimeException('Order does not belong to the bot.');
            }

            $existing = Fill::query()
                ->where('exchange', $fillData->exchange)
                ->where('exchange_fill_id', $fillData->exchangeFillId)
                ->first();

            if ($existing instanceof Fill) {
                return new FillResult($existing, $lockedOrder, duplicate: true);
            }

            if (! in_array($lockedOrder->status, [OrderStatus::Open, OrderStatus::PartiallyFilled], true)) {
                throw new RuntimeException("Cannot fill an order in [{$lockedOrder->status->value}] status.");
            }

            $remaining = Decimal::of($lockedOrder->quantity)->sub($lockedOrder->filled_quantity);

            if ($fillData->quantity->greaterThan($remaining)) {
                throw new RuntimeException('Fill quantity exceeds the order remaining quantity.');
            }

            $fill = Fill::create([
                'bot_id' => $lockedBot->id,
                'order_id' => $lockedOrder->id,
                'exchange' => $fillData->exchange,
                'exchange_fill_id' => $fillData->exchangeFillId,
                'side' => $lockedOrder->side,
                'price' => $fillData->price->toString(),
                'quantity' => $fillData->quantity->toString(),
                'fee' => $fillData->fee->toString(),
                'fee_currency' => $fillData->feeCurrency,
                'filled_at' => $fillData->filledAt ?? now(),
            ]);

            $filled = Decimal::of($lockedOrder->filled_quantity)->add($fillData->quantity);
            $status = $filled->greaterThanOrEqual($lockedOrder->quantity)
                ? OrderStatus::Filled
                : OrderStatus::PartiallyFilled;

            if ($status !== $lockedOrder->status) {
                $this->stateMachine->transition($lockedOrder, $status, ['filled_quantity' => $filled->toString()]);
            } else {
                $lockedOrder->forceFill(['filled_quantity' => $filled->toString()])->save();
            }

            if ($status === OrderStatus::Filled && $lockedOrder->grid_level_id !== null) {
                $lockedOrder->gridLevel?->update(['state' => \App\Domain\Grid\Enums\GridLevelState::Filled]);
            }

            $this->activity->record(
                'order.filled',
                "Order {$lockedOrder->client_order_id} filled",
                $lockedBot,
                context: [
                    'order_id' => $lockedOrder->id,
                    'fill_id' => $fill->id,
                    'side' => $lockedOrder->side->value,
                    'price' => $fillData->price->toString(),
                    'quantity' => $fillData->quantity->toString(),
                ],
            );

            $this->ledger->apply($lockedBot, $fill);

            return new FillResult($fill, $lockedOrder->refresh(), opposite: $this->oppositeIntent($lockedOrder, $fillData));
            });
        } catch (NegativeInventoryException $exception) {
            $bot->refresh()->forceFill([
                'status' => \App\Domain\Bot\Enums\BotStatus::Paused,
                'last_error' => $exception->getMessage(),
            ])->save();

            $this->activity->record('bot.paused', $exception->getMessage(), $bot, context: [
                'reason' => 'negative_inventory',
                'order_id' => $order->id,
            ], severity: 'error');

            throw $exception;
        }
    }

    private function oppositeIntent(Order $order, FillData $fill): ?OrderIntent
    {
        $order->loadMissing(['grid', 'gridLevel']);

        if ($order->grid === null || $order->gridLevel === null) {
            return null;
        }

        $target = $order->side === OrderSide::Buy
            ? $order->gridLevel->sequence + 1
            : $order->gridLevel->sequence - 1;

        $level = $order->grid->levels()->where('sequence', $target)->first();

        if ($level === null) {
            return null;
        }

        return OrderIntent::limit(
            $order->side->opposite(),
            Decimal::of($level->price),
            $fill->quantity,
            $level->sequence,
            'fill.opposite',
        );
    }
}
