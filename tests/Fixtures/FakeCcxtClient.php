<?php

namespace Tests\Fixtures;

use Throwable;

class FakeCcxtClient
{
    /** @var list<array{method: string, args: array}> */
    public array $calls = [];

    public bool $sandbox = false;

    public function __construct(
        public array $ticker = ['last' => 100.5, 'bid' => 100, 'ask' => 101, 'timestamp' => 1700000000000],
        public array $market = [
            'precision' => ['price' => 2, 'amount' => 6],
            'limits' => ['amount' => ['min' => '0.000001'], 'cost' => ['min' => '1']],
        ],
        public array $balance = [
            'free' => ['USDT' => '950.5', 'BTC' => '0.5'],
            'used' => ['USDT' => '49.5', 'BTC' => '0'],
            'total' => ['USDT' => '1000', 'BTC' => '0.5'],
        ],
        public array $order = [
            'id' => 'ccxt-order-1',
            'clientOrderId' => 'client-1',
            'status' => 'open',
            'side' => 'buy',
            'price' => '100',
            'amount' => '1',
            'filled' => '0',
            'average' => null,
            'timestamp' => 1700000000000,
        ],
        public array $openOrders = [],
        /** @var list<array<int, mixed>> */
        public array $ohlcv = [
            [1700000000000, '100', '101', '99', '100.5', '10'],
            [1700000300000, '101', '102', '100', '101.5', '5'],
        ],
        public ?Throwable $error = null,
        public ?string $failMethod = null,
        public int $failures = 0,
    ) {}

    public function load_markets(): array
    {
        $this->record('load_markets');

        return ['BTC/USDT' => $this->market];
    }

    public function fetch_ticker(string $symbol): array
    {
        $this->record('fetch_ticker', [$symbol]);
        $this->maybeFail('fetch_ticker');

        return $this->ticker;
    }

    public function market(string $symbol): array
    {
        $this->record('market', [$symbol]);

        return $this->market;
    }

    public function set_sandbox_mode(bool $enabled): void
    {
        $this->sandbox = $enabled;
        $this->record('set_sandbox_mode', [$enabled]);
    }

    public function fetch_balance(): array
    {
        $this->record('fetch_balance');
        $this->maybeFail('fetch_balance');

        return $this->balance;
    }

    public function create_order(string $symbol, string $type, string $side, mixed $amount, mixed $price, array $params = []): array
    {
        $this->record('create_order', [$symbol, $type, $side, $amount, $price, $params]);
        $this->maybeFail('create_order');

        return $this->order;
    }

    public function cancel_order(string $id, ?string $symbol = null): array
    {
        $this->record('cancel_order', [$id, $symbol]);
        $this->maybeFail('cancel_order');

        return $this->order;
    }

    public function fetch_order(string $id, ?string $symbol = null): array
    {
        $this->record('fetch_order', [$id, $symbol]);
        $this->maybeFail('fetch_order');

        return $this->order;
    }

    public function fetch_open_orders(?string $symbol = null): array
    {
        $this->record('fetch_open_orders', [$symbol]);
        $this->maybeFail('fetch_open_orders');

        return $this->openOrders === [] ? [$this->order] : $this->openOrders;
    }

    public function fetch_ohlcv(string $symbol, ?string $timeframe = null, ?int $since = null, ?int $limit = null): array
    {
        $this->record('fetch_ohlcv', [$symbol, $timeframe, $since, $limit]);
        $this->maybeFail('fetch_ohlcv');

        return $this->ohlcv;
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    private function record(string $method, array $args = []): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function maybeFail(string $method): void
    {
        if ($this->failMethod === $method && $this->failures > 0) {
            $this->failures--;

            throw $this->error ?? new \RuntimeException('Fake failure');
        }
    }
}
