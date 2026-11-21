<?php

namespace App\Jobs;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\FeeResolver;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\FillProcessor;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Order\ValueObjects\FillData;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncOrders implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 10;

    public function __construct(public readonly int $botId) {}

    public function uniqueId(): string
    {
        return 'sync-orders:'.$this->botId;
    }

    public function handle(
        ExchangeClientFactory $factory,
        OrderStateMachine $stateMachine,
        FillProcessor $fills,
        ActivityRecorder $activity,
        LiveModeGuard $liveMode,
        BotTickPipeline $pipeline,
    ): void {
        $bot = Bot::query()->find($this->botId);

        if (! $bot instanceof Bot || ! $bot->isLive()) {
            return;
        }

        try {
            $liveMode->assertTradingAllowed($bot);
        } catch (LiveTradingDisabledException) {
            return;
        }

        $client = $factory->forBot($bot);
        $orders = $bot->orders()
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Open->value, OrderStatus::PartiallyFilled->value])
            ->get();

        $remoteOpen = [];

        try {
            foreach ($client->openOrders($bot, $bot->symbol) as $remote) {
                $remoteOpen[$remote->exchangeOrderId] = $remote;
            }
        } catch (ExchangeException) {
            // Fall back to per-order lookups below.
        }

        $remoteByClientId = [];

        foreach ($remoteOpen as $remote) {
            $remoteByClientId[$remote->clientOrderId] = $remote;
        }

        $marketData = null;

        foreach ($orders as $order) {
            $remote = null;

            if ($order->exchange_order_id === null || $order->exchange_order_id === '') {
                $candidate = $remoteByClientId[$order->client_order_id] ?? null;

                if ($candidate === null) {
                    // The exchange outcome is still unknown. Leave it pending and
                    // let a later sync or reconciliation resolve it; never place
                    // a replacement order for the same client order id.
                    continue;
                }

                $order->forceFill(['exchange_order_id' => $candidate->exchangeOrderId])->save();

                if ($order->status === OrderStatus::Pending) {
                    $stateMachine->transition($order, OrderStatus::Open);
                }

                $remote = $candidate;
            } else {
                $remote = $remoteOpen[$order->exchange_order_id] ?? null;

                if ($remote !== null) {
                    if ($order->status === OrderStatus::Pending) {
                        $stateMachine->transition($order, OrderStatus::Open);
                    }
                } else {
                    try {
                        $remote = $client->order($bot, $bot->symbol, $order->exchange_order_id);
                    } catch (ExchangeException $exception) {
                        if ($exception->type === ExchangeErrorType::NotFound && $order->status !== OrderStatus::Canceled) {
                            $stateMachine->transition($order, OrderStatus::Canceled);
                        }

                        continue;
                    }
                }
            }

            $filled = $remote->filledQuantity;

            if ($filled->greaterThan($order->filled_quantity) && $order->status->isOpen()) {
                $delta = $filled->sub($order->filled_quantity);
                $price = Decimal::of($remote->averagePrice->isPositive() ? $remote->averagePrice : $order->price);
                $recorded = Decimal::zero();

                foreach ($order->fills()->get() as $recordedFill) {
                    if (($recordedFill->fee_currency ?: FeeResolver::quoteCurrency($bot)) === $remote->feeCurrency) {
                        $recorded = $recorded->add($recordedFill->fee);
                    }
                }

                [$fee, $feeCurrency] = FeeResolver::forFill($bot, $remote, $price, $delta, $recorded);

                try {
                    $result = $fills->process($bot, $order, FillData::make(
                        $client->exchange(),
                        'sync-'.$remote->exchangeOrderId.'-'.$filled->toString(),
                        $price,
                        $delta,
                        $fee->toString(),
                        $feeCurrency,
                    ));

                    $marketData ??= $client->market($bot->symbol);
                    $pipeline->recordFill($bot, $client, $marketData, $order, $result);
                } catch (Throwable $exception) {
                    report($exception);
                }

                $order->refresh();
            }

            if ($order->status->isOpen() && $remote->status->isTerminal() && $order->status !== $remote->status) {
                $fullyFilled = Decimal::of($order->filled_quantity)->greaterThanOrEqual($remote->quantity);

                if ($remote->status !== OrderStatus::Filled || $fullyFilled) {
                    $stateMachine->transition($order, $remote->status);
                }
            }
        }

        $activity->record(
            'orders.synced',
            'Exchange orders synchronized',
            $bot,
            context: ['checked' => $orders->count()],
        );
    }

}
