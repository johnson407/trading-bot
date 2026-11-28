<?php

namespace Tests\Fixtures;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ValueObjects\Balance;
use App\Domain\Exchange\ValueObjects\Candle;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use DateTimeImmutable;
use DateTimeZone;

class FakeExchangeClient implements ExchangeClient
{
    /** @var array<string, ExchangeOrder> */
    public array $orders = [];

    /** @var list<array{symbol: string, exchange_order_id: string|null, client_order_id: string|null}> */
    public array $cancelCalls = [];

    /** @var list<array{side: OrderSide, price: string, quantity: string, client_order_id: string}> */
    public array $placeCalls = [];

    public int $openOrdersCalls = 0;

    public int $balanceCalls = 0;

    public ?\Throwable $cancelFailure = null;

    public bool $timeoutAfterAccept = false;

    /** @var (\Closure(string, string, string): void)|null */
    public ?\Closure $onPlace = null;

    /** @var list<array{symbol: string, timeframe: string, limit: int}> */
    public array $candleCalls = [];

    public ?\Throwable $candleFailure = null;

    /** @var list<Candle>|null */
    public ?array $candles = null;

    public function __construct(
        public string $price = '100',
        public string $quoteBalance = '1000',
        public string $baseBalance = '0',
        private int $sequence = 0,
        ?array $candles = null,
    ) {
        $this->candles = $candles;
    }

    public function exchange(): string
    {
        return 'fake';
    }

    public function market(string $symbol): MarketData
    {
        return MarketData::make($symbol, $this->price, pricePrecision: 2, amountPrecision: 6, minAmount: '0.000001', minNotional: '1');
    }

    public function candles(string $symbol, string $timeframe, int $limit): array
    {
        $this->candleCalls[] = ['symbol' => $symbol, 'timeframe' => $timeframe, 'limit' => $limit];

        if ($this->candleFailure !== null) {
            throw $this->candleFailure;
        }

        return $this->candles ?? [
            new Candle((new DateTimeImmutable('@1700000000'))->setTimezone(new DateTimeZone('UTC')), Decimal::of('100.5')),
            new Candle((new DateTimeImmutable('@1700000300'))->setTimezone(new DateTimeZone('UTC')), Decimal::of('101.5')),
        ];
    }

    public function balances(Bot $bot): array
    {
        $this->balanceCalls++;

        return [
            Balance::make('USDT', $this->quoteBalance),
            Balance::make('BTC', $this->baseBalance),
        ];
    }

    public function placeLimitOrder(
        Bot $bot,
        string $symbol,
        OrderSide $side,
        Decimal $price,
        Decimal $quantity,
        string $clientOrderId,
    ): ExchangeOrder {
        if ($this->onPlace !== null) {
            ($this->onPlace)($clientOrderId, $side->value, $price->toString());
        }

        $this->placeCalls[] = [
            'side' => $side,
            'price' => $price->toString(),
            'quantity' => $quantity->toString(),
            'client_order_id' => $clientOrderId,
        ];

        $id = 'fake-'.(++$this->sequence);

        $this->orders[$id] = ExchangeOrder::make(
            $id,
            $clientOrderId,
            OrderStatus::Open,
            $side,
            $price,
            $quantity,
        );

        if ($this->timeoutAfterAccept) {
            throw ExchangeException::make(
                ExchangeErrorType::Connection,
                'fake',
                'createOrder',
                'The request timed out after the exchange accepted the order.',
                ['client_order_id' => $clientOrderId],
            );
        }

        return $this->orders[$id];
    }

    public function cancelOrder(
        Bot $bot,
        string $symbol,
        ?string $exchangeOrderId,
        ?string $clientOrderId = null,
    ): void {
        $this->cancelCalls[] = [
            'symbol' => $symbol,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => $clientOrderId,
        ];

        if ($this->cancelFailure !== null) {
            throw $this->cancelFailure;
        }

        $exchangeOrderId ??= $this->exchangeIDForClientID((string) $clientOrderId);
        $order = $this->orderOrFail($exchangeOrderId);

        $this->orders[$exchangeOrderId] = ExchangeOrder::make(
            $order->exchangeOrderId,
            $order->clientOrderId,
            OrderStatus::Canceled,
            $order->side,
            $order->price,
            $order->quantity,
            $order->filledQuantity,
        );
    }

    private function exchangeIDForClientID(string $clientOrderId): string
    {
        foreach ($this->orders as $exchangeOrderId => $order) {
            if ($order->clientOrderId === $clientOrderId) {
                return $exchangeOrderId;
            }
        }

        throw ExchangeException::make(
            ExchangeErrorType::NotFound,
            'fake',
            'cancelOrder',
            'Order not found.',
        );
    }

    public function order(Bot $bot, string $symbol, string $exchangeOrderId): ExchangeOrder
    {
        return $this->orderOrFail($exchangeOrderId);
    }

    public function openOrders(Bot $bot, string $symbol): array
    {
        $this->openOrdersCalls++;

        return array_values(array_filter(
            $this->orders,
            fn (ExchangeOrder $order) => $order->status->isOpen(),
        ));
    }

    public function fill(
        string $exchangeOrderId,
        string $price,
        string $quantity,
        ?string $fee = null,
        string $feeCurrency = 'USDT',
    ): void {
        $order = $this->orderOrFail($exchangeOrderId);

        $this->orders[$exchangeOrderId] = ExchangeOrder::make(
            $order->exchangeOrderId,
            $order->clientOrderId,
            OrderStatus::Filled,
            $order->side,
            $order->price,
            $order->quantity,
            $quantity,
            $price,
            fee: $fee,
            feeCurrency: $feeCurrency,
        );
    }

    private function orderOrFail(string $exchangeOrderId): ExchangeOrder
    {
        if (! isset($this->orders[$exchangeOrderId])) {
            throw ExchangeException::make(
                ExchangeErrorType::NotFound,
                $this->exchange(),
                'order',
                'Order not found.',
                ['exchange_order_id' => $exchangeOrderId],
            );
        }

        return $this->orders[$exchangeOrderId];
    }
}
