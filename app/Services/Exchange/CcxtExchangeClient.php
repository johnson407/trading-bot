<?php

namespace App\Services\Exchange;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\Enums\ExchangeErrorType;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ExchangeErrorMapper;
use App\Domain\Exchange\ValueObjects\Balance;
use App\Domain\Exchange\ValueObjects\ExchangeOrder;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Support\Decimal;
use App\Domain\Support\Number;
use App\Models\Bot;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class CcxtExchangeClient implements ExchangeClient
{
    private ?object $publicClient = null;

    private ?object $privateClient = null;

    private readonly int $maxAttempts;

    private readonly int $retryDelayMs;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $sleeper;

    public function __construct(
        ?callable $clientFactory = null,
        ?callable $sleeper = null,
        private readonly ?ExchangeErrorMapper $mapper = null,
        ?int $maxAttempts = null,
        ?int $retryDelayMs = null,
        private readonly ?string $exchange = null,
    ) {
        $this->clientFactory = $clientFactory;
        $this->sleeper = $sleeper;
        $this->maxAttempts = $maxAttempts ?? (int) config('trading.exchange.max_retries', 3);
        $this->retryDelayMs = $retryDelayMs ?? (int) config('trading.exchange.retry_delay_ms', 250);
    }

    public function exchange(): string
    {
        return $this->exchange ?? (string) config('trading.default_exchange', 'binance');
    }

    public function market(string $symbol): MarketData
    {
        return $this->withRetry('market', function () use ($symbol) {
            $client = $this->publicClient();
            $client->load_markets();
            $ticker = $client->fetch_ticker($symbol);
            $market = $client->market($symbol);

            $last = $ticker['last'] ?? $ticker['close'] ?? null;

            if ($last === null) {
                throw ExchangeException::make(
                    ExchangeErrorType::Unknown,
                    $this->exchange(),
                    'market',
                    'Ticker did not contain a last price.',
                    ['symbol' => $symbol],
                );
            }

            return MarketData::make(
                $symbol,
                Number::decimalString($last),
                isset($ticker['bid']) ? Number::decimalString($ticker['bid']) : null,
                isset($ticker['ask']) ? Number::decimalString($ticker['ask']) : null,
                $this->precisionToDecimals($market['precision']['price'] ?? 8),
                $this->precisionToDecimals($market['precision']['amount'] ?? 8),
                Number::decimalString($market['limits']['amount']['min'] ?? '0'),
                Number::decimalString($market['limits']['cost']['min'] ?? '0'),
                isset($ticker['timestamp'])
                    ? (new DateTimeImmutable('@'.intdiv((int) $ticker['timestamp'], 1000)))->setTimezone(new DateTimeZone('UTC'))
                    : null,
            );
        });
    }

    public function balances(Bot $bot): array
    {
        return $this->withRetry('balances', function () use ($bot) {
            $balance = $this->privateClient($bot)->fetch_balance();
            $balances = [];

            foreach (($balance['total'] ?? []) as $asset => $total) {
                $free = Number::decimalString($balance['free'][$asset] ?? 0);
                $used = Number::decimalString($balance['used'][$asset] ?? 0);
                $total = Number::decimalString($total);

                if (Decimal::of($total)->isZero() && Decimal::of($free)->isZero() && Decimal::of($used)->isZero()) {
                    continue;
                }

                $balances[] = Balance::make((string) $asset, $free, $used, $total);
            }

            return $balances;
        });
    }

    public function placeLimitOrder(
        Bot $bot,
        string $symbol,
        OrderSide $side,
        Decimal $price,
        Decimal $quantity,
        string $clientOrderId,
    ): ExchangeOrder {
        try {
            $order = $this->privateClient($bot)->create_order(
                $symbol,
                'limit',
                $side->value,
                $quantity->toString(),
                $price->toString(),
                ['clientOrderId' => $clientOrderId],
            );

            return $this->mapOrder($order);
        } catch (Throwable $exception) {
            throw $this->mapper()->map($exception, $this->exchange(), 'createOrder');
        }
    }

    public function cancelOrder(Bot $bot, string $symbol, string $exchangeOrderId): void
    {
        $this->withRetry('cancelOrder', function () use ($bot, $symbol, $exchangeOrderId) {
            $this->privateClient($bot)->cancel_order($exchangeOrderId, $symbol);

            return true;
        });
    }

    public function order(Bot $bot, string $symbol, string $exchangeOrderId): ExchangeOrder
    {
        return $this->withRetry('fetchOrder', function () use ($bot, $symbol, $exchangeOrderId) {
            return $this->mapOrder($this->privateClient($bot)->fetch_order($exchangeOrderId, $symbol));
        });
    }

    public function openOrders(Bot $bot, string $symbol): array
    {
        return $this->withRetry('fetchOpenOrders', function () use ($bot, $symbol) {
            $orders = $this->privateClient($bot)->fetch_open_orders($symbol);

            return array_map(fn (array $order) => $this->mapOrder($order), $orders);
        });
    }

    /** @param array<string, mixed> $order */
    public function mapOrder(array $order): ExchangeOrder
    {
        return ExchangeOrder::make(
            (string) ($order['id'] ?? ''),
            (string) ($order['clientOrderId'] ?? ''),
            $this->mapStatus((string) ($order['status'] ?? 'open')),
            OrderSide::from((string) ($order['side'] ?? 'buy')),
            Number::decimalString($order['price'] ?? '0'),
            Number::decimalString($order['amount'] ?? '0'),
            Number::decimalString($order['filled'] ?? '0'),
            isset($order['average']) ? Number::decimalString($order['average']) : null,
            isset($order['timestamp'])
                ? (new DateTimeImmutable('@'.intdiv((int) $order['timestamp'], 1000)))->setTimezone(new DateTimeZone('UTC'))
                : null,
            fee: $this->mapFeeCost($order),
            feeCurrency: $this->mapFeeCurrency($order),
        );
    }

    /** @param array<string, mixed> $order */
    private function mapFeeCost(array $order): string
    {
        $fee = $order['fee'] ?? ($order['fees'][0] ?? null);

        return is_array($fee) ? Number::decimalString($fee['cost'] ?? '0') : '0';
    }

    /** @param array<string, mixed> $order */
    private function mapFeeCurrency(array $order): string
    {
        $fee = $order['fee'] ?? ($order['fees'][0] ?? null);

        if (is_array($fee) && isset($fee['currency'])) {
            return (string) $fee['currency'];
        }

        $symbol = (string) ($order['symbol'] ?? '');

        return explode('/', $symbol)[1] ?? 'USDT';
    }

    private function mapStatus(string $status): OrderStatus
    {
        return match ($status) {
            'closed', 'filled' => OrderStatus::Filled,
            'canceled', 'cancelled', 'expired' => OrderStatus::Canceled,
            'rejected' => OrderStatus::Rejected,
            'partially_filled' => OrderStatus::PartiallyFilled,
            default => OrderStatus::Open,
        };
    }

    private function precisionToDecimals(mixed $precision): int
    {
        if (is_int($precision)) {
            return max(0, $precision);
        }

        if (is_float($precision) && $precision > 0) {
            return max(0, (int) ceil(-log10($precision)));
        }

        if (is_numeric($precision) && (float) $precision > 0) {
            return max(0, (int) ceil(-log10((float) $precision)));
        }

        return 8;
    }

    private function publicClient(): object
    {
        if ($this->publicClient !== null) {
            return $this->publicClient;
        }

        return $this->publicClient = $this->createClient([]);
    }

    private function privateClient(Bot $bot): object
    {
        if ($this->privateClient !== null) {
            return $this->privateClient;
        }

        $credential = $bot->credential;

        if ($credential === null) {
            throw ExchangeException::make(
                ExchangeErrorType::Authentication,
                $this->exchange(),
                'authentication',
                'Exchange credentials are required for private operations.',
                ['bot_id' => $bot->id],
            );
        }

        $options = [
            'apiKey' => (string) $credential->api_key,
            'secret' => (string) $credential->api_secret,
        ];

        if ($credential->passphrase !== null) {
            $options['password'] = (string) $credential->passphrase;
        }

        if ($credential->testnet) {
            $options['sandbox'] = true;
        }

        $client = $this->createClient($options);

        if ($credential->testnet && method_exists($client, 'set_sandbox_mode')) {
            $client->set_sandbox_mode(true);
        }

        return $this->privateClient = $client;
    }

    /** @param array<string, mixed> $options */
    private function createClient(array $options): object
    {
        $options = array_merge([
            'enableRateLimit' => true,
            'rateLimit' => (int) config('trading.exchange.rate_limit_ms', 1000),
            'timeout' => (int) config('trading.exchange.timeout_ms', 10000),
        ], $options);

        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($options);
        }

        $class = '\\ccxt\\'.strtolower($this->exchange());

        if (! class_exists($class)) {
            throw ExchangeException::make(
                ExchangeErrorType::Unsupported,
                $this->exchange(),
                'construct',
                "CCXT exchange class [{$class}] is not available.",
            );
        }

        return new $class($options);
    }

    private function withRetry(string $operation, callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $callback();
            } catch (Throwable $exception) {
                $mapped = $this->mapper()->map($exception, $this->exchange(), $operation);

                if (! $mapped->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $mapped;
                }

                $this->sleep($this->retryDelayMs * (2 ** ($attempt - 1)));
            }
        }
    }

    private function sleep(int $milliseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private function mapper(): ExchangeErrorMapper
    {
        return $this->mapper ?? new ExchangeErrorMapper;
    }
}
