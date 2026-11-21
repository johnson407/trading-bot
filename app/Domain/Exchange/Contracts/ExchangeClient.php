<?php

namespace App\Domain\Exchange\Contracts;

use App\Domain\Exchange\ValueObjects\Balance;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;
use App\Models\Bot;

interface ExchangeClient
{
    public function exchange(): string;

    public function market(string $symbol): MarketData;

    /** @return list<Balance> */
    public function balances(Bot $bot): array;

    public function placeLimitOrder(
        Bot $bot,
        string $symbol,
        OrderSide $side,
        Decimal $price,
        Decimal $quantity,
        string $clientOrderId,
    ): ExchangeOrder;

    public function cancelOrder(Bot $bot, string $symbol, string $exchangeOrderId): void;

    public function order(Bot $bot, string $symbol, string $exchangeOrderId): ExchangeOrder;

    /** @return list<ExchangeOrder> */
    public function openOrders(Bot $bot, string $symbol): array;
}
