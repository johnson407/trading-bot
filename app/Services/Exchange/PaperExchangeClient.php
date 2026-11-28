<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Exchange\ValueObjects\PaperFill;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Order;

class PaperExchangeClient implements ExchangeClient
{
    public function __construct(private readonly ?ExchangeClient $publicData = null) {}

    public function exchange(): string
    {
        return 'paper';
    }

    public function market(string $symbol): MarketData
    {
        return ($this->publicData ?? app(CcxtExchangeClient::class))->market($symbol);
    }

    public function candles(string $symbol, string $timeframe, int $limit): array
    {
        return ($this->publicData ?? app(CcxtExchangeClient::class))->candles($symbol, $timeframe, $limit);
    }

    public function balances(Bot $bot): array
    {
        return $this->privateOperation('balances');
    }

    public function placeLimitOrder(
        Bot $bot,
        string $symbol,
        OrderSide $side,
        Decimal $price,
        Decimal $quantity,
        string $clientOrderId,
    ): ExchangeOrder {
        return $this->privateOperation('createOrder');
    }

    public function cancelOrder(
        Bot $bot,
        string $symbol,
        ?string $exchangeOrderId,
        ?string $clientOrderId = null,
    ): void {
        $this->privateOperation('cancelOrder');
    }

    public function order(Bot $bot, string $symbol, string $exchangeOrderId): ExchangeOrder
    {
        return $this->privateOperation('fetchOrder');
    }

    public function openOrders(Bot $bot, string $symbol): array
    {
        return $this->privateOperation('fetchOpenOrders');
    }

    /**
     * Return paper fills for open limit orders crossed by the market price.
     * Orders closest to the market are filled first, and a market that gaps
     * through the limit fills at the better market price.
     *
     * @param  iterable<Order>  $orders
     * @return list<PaperFill>
     */
    public function qualifyingFills(MarketData $market, iterable $orders, Decimal|string $feeRate = '0'): array
    {
        $feeRate = Decimal::of($feeRate);
        $qualifying = [];

        foreach ($orders as $order) {
            if ($order->status !== OrderStatus::Open && $order->status !== OrderStatus::PartiallyFilled) {
                continue;
            }

            $limit = Decimal::of($order->price);
            $crossed = $order->side === OrderSide::Buy
                ? $market->last->lessThanOrEqual($limit)
                : $market->last->greaterThanOrEqual($limit);

            if (! $crossed) {
                continue;
            }

            $qualifying[] = $order;
        }

        usort($qualifying, function (Order $a, Order $b) use ($market) {
            $distanceA = Decimal::of($a->price)->sub($market->last)->abs();
            $distanceB = Decimal::of($b->price)->sub($market->last)->abs();

            return $distanceA->compare($distanceB) ?: Decimal::of($a->price)->compare($b->price);
        });

        return array_map(function (Order $order) use ($feeRate, $market) {
            $quantity = Decimal::of($order->quantity)->sub($order->filled_quantity);
            $limit = Decimal::of($order->price);
            $price = $limit;

            if ($order->side === OrderSide::Buy && $market->last->lessThan($limit)) {
                $price = $market->last;
            }

            if ($order->side === OrderSide::Sell && $market->last->greaterThan($limit)) {
                $price = $market->last;
            }

            $fee = $price->mul($quantity)->mul($feeRate);

            return new PaperFill(
                $order->id,
                $order->client_order_id,
                $order->side,
                $price,
                $quantity,
                $fee,
                'USDT',
            );
        }, $qualifying);
    }

    private function privateOperation(string $operation): never
    {
        throw ExchangeException::make(
            ExchangeErrorType::Unsupported,
            $this->exchange(),
            $operation,
            'Paper mode does not support private exchange operations.',
        );
    }
}
