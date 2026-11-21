<?php

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Services\Exchange\CcxtExchangeClient;

it('places and cancels a small testnet limit order', function () {
    if (getenv('CCXT_TESTNET') !== '1') {
        $this->markTestSkipped('Set CCXT_TESTNET=1 to run the Binance testnet integration suite.');
    }

    $apiKey = getenv('BINANCE_TESTNET_API_KEY');
    $apiSecret = getenv('BINANCE_TESTNET_API_SECRET');

    if (! $apiKey || ! $apiSecret) {
        $this->markTestSkipped('Set BINANCE_TESTNET_API_KEY and BINANCE_TESTNET_API_SECRET.');
    }

    $symbol = getenv('CCXT_TESTNET_SYMBOL') ?: 'BTC/USDT';
    $client = new CcxtExchangeClient;
    $market = $client->market($symbol);

    expect($market)->toBeInstanceOf(MarketData::class)
        ->and($market->last->isPositive())->toBeTrue();

    $price = $market->last->mul('0.5')->toFixed($market->pricePrecision ?? 2);
    $amount = Decimal::of(getenv('CCXT_TESTNET_AMOUNT') ?: '0.0001');

    if ($market->minAmount !== null && $amount->lessThan($market->minAmount)) {
        $amount = Decimal::of($market->minAmount);
    }

    if ($market->minNotional !== null) {
        $notional = Decimal::of($price)->mul($amount);

        if ($notional->lessThan($market->minNotional)) {
            $amount = Decimal::of($market->minNotional)->div($price)->toFixed($market->amountPrecision ?? 6);
        }
    }

    $credential = new ExchangeCredential([
        'exchange' => 'binance',
        'api_key' => $apiKey,
        'api_secret' => $apiSecret,
        'testnet' => true,
    ]);

    $bot = new Bot([
        'exchange' => 'binance',
        'symbol' => $symbol,
        'mode' => 'live',
    ]);
    $bot->setRelation('credential', $credential);

    $order = $client->placeLimitOrder(
        $bot,
        $symbol,
        OrderSide::Buy,
        Decimal::of($price),
        $amount,
        'testnet-'.bin2hex(random_bytes(6)),
    );

    expect($order->exchangeOrderId)->not->toBeEmpty();

    $client->cancelOrder($bot, $symbol, $order->exchangeOrderId);
})->group('testnet');
