<?php

namespace App\Domain\Bot;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Bot\ValueObjects\ReconcileResult;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\FillProcessor;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Order\ValueObjects\FillData;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Risk\RiskEventRecorder;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Order;
use App\Services\Exchange\ExchangeClientFactory;
use Throwable;

class BotReconciler
{
    public function __construct(
        private readonly ExchangeClientFactory $clients,
        private readonly FillProcessor $fills,
        private readonly OrderStateMachine $stateMachine,
        private readonly ActivityRecorder $activity,
        private readonly RiskEventRecorder $riskEvents,
        private readonly LiveModeGuard $liveMode,
        private readonly BotTickPipeline $pipeline,
    ) {}

    public function reconcile(Bot $bot): ReconcileResult
    {
        $bot->refresh();

        if (! $bot->isRunning()) {
            return ReconcileResult::skipped();
        }

        if ($bot->mode === BotMode::Paper) {
            return new ReconcileResult('ok');
        }

        try {
            $this->liveMode->assertTradingAllowed($bot);
        } catch (LiveTradingDisabledException) {
            return ReconcileResult::skipped();
        }

        $client = $this->clients->forBot($bot);
        $local = $bot->orders()
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Open->value, OrderStatus::PartiallyFilled->value])
            ->get();

        $remote = [];
        $remoteByClientId = [];

        try {
            foreach ($client->openOrders($bot, $bot->symbol) as $order) {
                $remote[$order->exchangeOrderId] = $order;
                $remoteByClientId[$order->clientOrderId] = $order;
            }
        } catch (Throwable $exception) {
            return $this->conflict($bot, ['Could not fetch remote open orders: '.$exception->getMessage()]);
        }

        $matched = 0;
        $filled = 0;
        $canceled = 0;
        $conflicts = [];
        $localIds = [];
        $marketData = null;

        foreach ($local as $order) {
            $remoteOrder = null;

            if ($order->exchange_order_id === null || $order->exchange_order_id === '') {
                $remoteOrder = $remoteByClientId[$order->client_order_id] ?? null;

                if ($remoteOrder === null) {
                    // The exchange outcome is unknown; leave the order pending
                    // rather than placing a replacement.
                    continue;
                }

                $order->forceFill(['exchange_order_id' => $remoteOrder->exchangeOrderId])->save();
            } else {
                $localIds[$order->exchange_order_id] = true;
                $remoteOrder = $remote[$order->exchange_order_id] ?? null;

                if ($remoteOrder !== null) {
                    if ($order->status === OrderStatus::Pending) {
                        $this->stateMachine->transition($order, OrderStatus::Open);
                    }
                } else {
                    try {
                        $remoteOrder = $client->order($bot, $bot->symbol, $order->exchange_order_id);
                    } catch (ExchangeException $exception) {
                        if ($exception->type === ExchangeErrorType::NotFound) {
                            $this->stateMachine->transition($order, OrderStatus::Canceled);
                            $canceled++;
                            continue;
                        }

                        $conflicts[] = "Order {$order->client_order_id} could not be reconciled: {$exception->getMessage()}";
                        continue;
                    }
                }
            }

            $localIds[$remoteOrder->exchangeOrderId] = true;
            $didFill = false;

            if ($order->status === OrderStatus::Pending && $remoteOrder->status->isOpen()) {
                $this->stateMachine->transition($order, OrderStatus::Open);
            }

            if ($remoteOrder->filledQuantity->greaterThan($order->filled_quantity) && $order->status->isOpen()) {
                $marketData ??= $client->market($bot->symbol);
                $didFill = $this->applyFill($bot, $order, $remoteOrder, $client, $marketData);
                $order->refresh();
            }

            if ($order->status->isOpen()) {
                if (in_array($remoteOrder->status, [OrderStatus::Canceled, OrderStatus::Rejected], true)) {
                    $this->stateMachine->transition($order, $remoteOrder->status);
                    $canceled++;
                    continue;
                }

                if ($remoteOrder->status === OrderStatus::Filled
                    && Decimal::of($order->filled_quantity)->greaterThanOrEqual($remoteOrder->quantity)) {
                    $this->stateMachine->transition($order, OrderStatus::Filled);
                }
            }

            if ($didFill) {
                $filled++;
            } else {
                $matched++;
            }
        }

        foreach ($remote as $exchangeOrderId => $remoteOrder) {
            if (! isset($localIds[$exchangeOrderId])) {
                $conflicts[] = "Exchange order {$exchangeOrderId} is not known locally.";
            }
        }

        $balanceConflict = $this->balanceConflict($bot, $client);
        if ($balanceConflict !== null) {
            $conflicts[] = $balanceConflict;
        }

        if ($conflicts !== []) {
            return $this->conflict($bot, $conflicts, $matched, $filled, $canceled);
        }

        $this->activity->record('bot.reconciled', 'Bot reconciled with the exchange', $bot, context: [
            'matched' => $matched,
            'filled' => $filled,
            'canceled' => $canceled,
        ]);

        return new ReconcileResult('ok', $matched, $filled, $canceled);
    }

    private function applyFill(
        Bot $bot,
        Order $order,
        ExchangeOrder $remote,
        ExchangeClient $client,
        MarketData $marketData,
    ): bool {
        $remoteFilled = Decimal::of($remote->filledQuantity);
        $localFilled = Decimal::of($order->filled_quantity);
        $remaining = $remoteFilled->sub($localFilled);

        if (! $remaining->isPositive()) {
            return false;
        }

        $result = $this->fills->process($bot, $order, FillData::make(
            $bot->exchange,
            "reconcile-{$remote->exchangeOrderId}-{$remoteFilled->toString()}",
            $remote->averagePrice,
            $remaining,
            '0',
        ));

        $this->pipeline->recordFill($bot, $client, $marketData, $order, $result);

        $bot->refresh();

        return true;
    }

    private function balanceConflict(Bot $bot, $client): ?string
    {
        try {
            $balances = $client->balances($bot);
        } catch (Throwable $exception) {
            return 'Could not fetch balances: '.$exception->getMessage();
        }

        $quoteAsset = explode('/', $bot->symbol)[1] ?? 'USDT';
        $balance = null;

        foreach ($balances as $candidate) {
            if ($candidate->asset === $quoteAsset) {
                $balance = $candidate;
                break;
            }
        }

        if ($balance === null) {
            return "No {$quoteAsset} balance was returned by the exchange.";
        }

        $expected = Decimal::of($bot->quote_inventory);
        $difference = $balance->free->sub($expected)->abs();
        $allowed = $expected->abs()->max('1')->mul(config('trading.reconciliation.tolerance', '0.0001'));

        if ($difference->greaterThan($allowed)) {
            return "Quote balance {$balance->free->toString()} differs from expected {$expected->toString()} beyond tolerance.";
        }

        return null;
    }

    /** @param list<string> $conflicts */
    private function conflict(Bot $bot, array $conflicts, int $matched = 0, int $filled = 0, int $canceled = 0): ReconcileResult
    {
        $bot->forceFill([
            'status' => BotStatus::Paused,
            'last_error' => implode(' ', $conflicts),
        ])->save();

        $this->activity->record('bot.reconciliation_conflict', 'Reconciliation paused the bot', $bot, context: [
            'conflicts' => $conflicts,
            'matched' => $matched,
            'filled' => $filled,
            'canceled' => $canceled,
        ], severity: 'error');

        $this->riskEvents->record('risk.reconciliation_conflict', 'Reconciliation conflict paused the bot', $bot, context: [
            'conflicts' => $conflicts,
        ], severity: RiskSeverity::Critical);

        return new ReconcileResult('conflict', $matched, $filled, $canceled, $conflicts);
    }
}
