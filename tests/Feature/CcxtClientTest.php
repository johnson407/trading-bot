<?php

use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ValueObjects\Candle;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Services\Exchange\CcxtExchangeClient;
use App\Services\Exchange\ExchangeClientFactory;
use ccxt\InvalidOrder;
use ccxt\NetworkError;
use ccxt\RateLimitExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeCcxtClient;

uses(RefreshDatabase::class);

it('normalizes public market data and trading rules', function () {
    $fake = new FakeCcxtClient;
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);

    $market = $client->market('BTC/USDT');

    expect($market->last->toString())->toBe('100.5')
        ->and($market->bid?->toString())->toBe('100')
        ->and($market->ask?->toString())->toBe('101')
        ->and($market->pricePrecision)->toBe(2)
        ->and($market->amountPrecision)->toBe(6)
        ->and($market->minAmount->toString())->toBe('0.000001')
        ->and($market->minNotional->toString())->toBe('1')
        ->and($fake->count('load_markets'))->toBe(1)
        ->and($fake->count('fetch_ticker'))->toBe(1);
});

it('retries rate limits with exponential backoff and succeeds', function () {
    $sleeps = [];
    $fake = new FakeCcxtClient(error: new RateLimitExceeded('slow down'), failMethod: 'fetch_ticker', failures: 1);
    $client = new CcxtExchangeClient(
        clientFactory: fn () => $fake,
        sleeper: function (int $ms) use (&$sleeps) {
            $sleeps[] = $ms;
        },
        maxAttempts: 3,
        retryDelayMs: 10,
    );

    $market = $client->market('BTC/USDT');

    expect($market->last->toString())->toBe('100.5')
        ->and($fake->count('fetch_ticker'))->toBe(2)
        ->and($sleeps)->toBe([10]);
});

it('exhausts bounded retries and maps network failures', function () {
    $sleeps = [];
    $fake = new FakeCcxtClient(error: new NetworkError('offline'), failMethod: 'fetch_ticker', failures: 5);
    $client = new CcxtExchangeClient(
        clientFactory: fn () => $fake,
        sleeper: function (int $ms) use (&$sleeps) {
            $sleeps[] = $ms;
        },
        maxAttempts: 3,
        retryDelayMs: 10,
    );

    try {
        $client->market('BTC/USDT');
        $this->fail('Expected an exchange exception.');
    } catch (ExchangeException $exception) {
        expect($exception->type)->toBe(ExchangeErrorType::Connection)
            ->and($exception->operation)->toBe('market')
            ->and($fake->count('fetch_ticker'))->toBe(3)
            ->and($sleeps)->toBe([10, 20]);
    }
});

it('places live limit orders and maps accepted orders', function () {
    $fake = new FakeCcxtClient;
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);
    $bot = botWithCredential();

    $order = $client->placeLimitOrder(
        $bot,
        'BTC/USDT',
        OrderSide::Buy,
        Decimal::of('100'),
        Decimal::of('1'),
        'client-1',
    );

    $call = collect($fake->calls)->firstWhere('method', 'create_order');

    expect($order->exchangeOrderId)->toBe('ccxt-order-1')
        ->and($order->status)->toBe(OrderStatus::Open)
        ->and($call['args'][0])->toBe('BTC/USDT')
        ->and($call['args'][1])->toBe('limit')
        ->and($call['args'][2])->toBe('buy')
        ->and($call['args'][3])->toBe('1')
        ->and($call['args'][4])->toBe('100')
        ->and($call['args'][5]['clientOrderId'])->toBe('client-1');
});

it('maps rejected orders and cancel, status and balance calls', function () {
    $fake = new FakeCcxtClient(error: new InvalidOrder('bad order'), failMethod: 'create_order', failures: 1);
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);
    $bot = botWithCredential();

    try {
        $client->placeLimitOrder($bot, 'BTC/USDT', OrderSide::Buy, Decimal::of('100'), Decimal::of('1'), 'client-1');
        $this->fail('Expected a rejection.');
    } catch (ExchangeException $exception) {
        expect($exception->type)->toBe(ExchangeErrorType::Rejected);
    }

    $client->cancelOrder($bot, 'BTC/USDT', 'ccxt-order-1');
    $order = $client->order($bot, 'BTC/USDT', 'ccxt-order-1');
    $balances = $client->balances($bot);
    $open = $client->openOrders($bot, 'BTC/USDT');

    expect($order->exchangeOrderId)->toBe('ccxt-order-1')
        ->and($balances)->toHaveCount(2)
        ->and($open)->toHaveCount(1)
        ->and($fake->count('cancel_order'))->toBe(1)
        ->and($fake->count('fetch_order'))->toBe(1)
        ->and($fake->count('fetch_balance'))->toBe(1)
        ->and($fake->count('fetch_open_orders'))->toBe(1);
});

it('uses testnet credentials and sandbox mode', function () {
    $fake = new FakeCcxtClient;
    $captured = null;
    $client = new CcxtExchangeClient(clientFactory: function (array $options) use (&$captured, $fake) {
        $captured = $options;

        return $fake;
    });
    $bot = botWithCredential(testnet: true);

    $client->balances($bot);

    expect($captured['apiKey'])->toBe('test-key')
        ->and($captured['secret'])->toBe('test-secret')
        ->and($captured['sandbox'])->toBeTrue()
        ->and($fake->sandbox)->toBeTrue();
});

function botWithCredential(bool $testnet = false): Bot
{
    $bot = Bot::factory()->create();
    $credential = ExchangeCredential::factory()->for($bot->user)->create([
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'testnet' => $testnet,
    ]);
    $bot->update(['exchange_credential_id' => $credential->id]);

    return $bot->refresh();
}

it('does not retry order creation after an ambiguous network failure', function () {
    $fake = new FakeCcxtClient(error: new NetworkError('timeout'), failMethod: 'create_order', failures: 5);
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake, maxAttempts: 3, retryDelayMs: 1);
    $bot = botWithCredential();

    try {
        $client->placeLimitOrder($bot, 'BTC/USDT', OrderSide::Buy, Decimal::of('100'), Decimal::of('1'), 'client-1');
        $this->fail('Expected a connection failure.');
    } catch (ExchangeException $exception) {
        expect($exception->type)->toBe(ExchangeErrorType::Connection)
            ->and($fake->count('create_order'))->toBe(1);
    }
});

it('parses tiny scientific-notation exchange values', function () {
    $fake = new FakeCcxtClient(
        ticker: ['last' => 1.0E-5, 'bid' => 1.0E-5, 'ask' => 1.1E-5, 'timestamp' => 1700000000000],
        market: [
            'precision' => ['price' => 8, 'amount' => 4],
            'limits' => ['amount' => ['min' => 1.0E-6], 'cost' => ['min' => 1.0E-7]],
        ],
    );
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);

    $market = $client->market('SHIB/USDT');

    expect($market->last->toString())->toBe('0.00001')
        ->and($market->bid?->toString())->toBe('0.00001')
        ->and($market->ask?->toString())->toBe('0.000011')
        ->and($market->minAmount->toString())->toBe('0.000001')
        ->and($market->minNotional->toString())->toBe('0.0000001');
});

it('normalizes public candles in ascending order and drops malformed and duplicate rows', function () {
    $fake = new FakeCcxtClient(ohlcv: [
        [1700000300000, '101', '102', '100', '101.5', '5'],
        [1700000000000, '100', '101', '99', '100.5', '10'],
        [1700000300000, '101', '102', '100', '101.6', '6'],
        [1700000600000, '102', '103', '101', null, '7'],
        ['not-a-time', '102', '103', '101', '102.5', '7'],
        [1700000900000, '103'],
    ]);
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);

    $candles = $client->candles('BTC/USDT', '5m', 3);

    expect($candles)->toHaveCount(2)
        ->and($candles[0])->toBeInstanceOf(Candle::class)
        ->and($candles[0]->openTime->format('U'))->toBe('1700000000')
        ->and($candles[0]->close->toString())->toBe('100.5')
        ->and($candles[1]->openTime->format('U'))->toBe('1700000300')
        ->and($candles[1]->close->toString())->toBe('101.5')
        ->and(array_slice(array_column($fake->calls, 'method'), 0, 2))->toBe(['load_markets', 'fetch_ohlcv'])
        ->and(collect($fake->calls)->firstWhere('method', 'fetch_ohlcv')['args'])->toBe(['BTC/USDT', '5m', null, 3]);
});

it('expands scientific-notation candle closes before decimal conversion', function () {
    $fake = new FakeCcxtClient(ohlcv: [
        [1700000000000, 1.0E-5, 1.1E-5, 0.9E-5, 1.0E-5, '1000'],
    ]);
    $client = new CcxtExchangeClient(clientFactory: fn () => $fake);

    $candles = $client->candles('SHIB/USDT', '1m', 1);

    expect($candles)->toHaveCount(1)
        ->and($candles[0]->close->toString())->toBe('0.00001');
});

it('maps candle failures to the candles operation', function () {
    $fake = new FakeCcxtClient(error: new NetworkError('offline'), failMethod: 'fetch_ohlcv', failures: 5);
    $client = new CcxtExchangeClient(
        clientFactory: fn () => $fake,
        maxAttempts: 1,
        retryDelayMs: 1,
    );

    try {
        $client->candles('BTC/USDT', '5m', 2);
        $this->fail('Expected an exchange exception.');
    } catch (ExchangeException $exception) {
        expect($exception->type)->toBe(ExchangeErrorType::Connection)
            ->and($exception->operation)->toBe('candles')
            ->and($fake->count('fetch_ohlcv'))->toBe(1);
    }
});

it('uses a bounded one-attempt public candle client when configured', function () {
    $options = null;
    $fake = new FakeCcxtClient(error: new NetworkError('offline'), failMethod: 'fetch_ohlcv', failures: 5);
    $client = new CcxtExchangeClient(
        clientFactory: function (array $clientOptions) use (&$options, $fake) {
            $options = $clientOptions;

            return $fake;
        },
        sleeper: fn () => null,
        exchange: 'binance',
        maxAttempts: 1,
        timeoutMs: 5000,
    );

    expect(fn () => $client->candles('BTC/USDT', '5m', 2))
        ->toThrow(ExchangeException::class);

    expect($options['timeout'])->toBe(5000)
        ->and($fake->count('fetch_ohlcv'))->toBe(1);
});

it('keeps bounded and default exchange clients separate', function () {
    $factory = new ExchangeClientFactory;

    $default = $factory->forExchange('binance');
    $bounded = $factory->forExchange('binance', 1, 5000);

    expect($bounded)->not->toBe($default)
        ->and($factory->forExchange('binance'))->toBe($default)
        ->and($factory->forExchange('binance', 1, 5000))->toBe($bounded);
});
