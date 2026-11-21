# Exchange Connectivity

## Supported exchanges

Binance is the default exchange. CCXT is the only exchange integration; the
application does not implement custom exchange adapters for the MVP. A bot may
select any exchange listed in `trading.exchanges`; the list defaults to
`binance` and is configured with the comma-separated `TRADING_EXCHANGES`
environment variable.

Configuration keys:

| Key | Environment variable | Default |
| --- | --- | --- |
| `trading.exchanges` | `TRADING_EXCHANGES` | `binance` |
| `trading.default_exchange` | `TRADING_DEFAULT_EXCHANGE` | `binance` |
| `trading.live_enabled` | `TRADING_LIVE_ENABLED` | `false` |
| `trading.paper.fee_rate` | `TRADING_PAPER_FEE_RATE` | `0` |
| `trading.exchange.timeout_ms` | `TRADING_EXCHANGE_TIMEOUT_MS` | `15000` |
| `trading.exchange.rate_limit_ms` | `TRADING_EXCHANGE_RATE_LIMIT_MS` | `1000` |
| `trading.exchange.max_retries` | `TRADING_EXCHANGE_MAX_RETRIES` | `3` |
| `trading.exchange.retry_delay_ms` | `TRADING_EXCHANGE_RETRY_DELAY_MS` | `250` |
| `trading.exchange.sandbox` | `TRADING_EXCHANGE_SANDBOX` | `false` |
| `trading.failure.max_consecutive` | `TRADING_MAX_CONSECUTIVE_FAILURES` | `3` |
| `trading.reconciliation.tolerance` | `TRADING_RECONCILIATION_TOLERANCE` | `0.0001` |
| `trading.orders.sync_interval_seconds` | `TRADING_ORDER_SYNC_SECONDS` | `5` |

Check the effective values with:

```bash
php artisan config:show trading
```

## Binance API keys

1. Sign in to Binance and open **API Management**.
2. Create an API key labelled for this bot.
3. Enable **Enable Reading** and **Enable Spot & Margin Trading**.
4. Do **not** enable withdrawals. The application never needs withdrawal
   permission.
5. Restrict the key to the server's egress IP address where possible.
6. Store the key and secret through the dashboard or
   `App\Actions\SaveExchangeCredential`; they are encrypted with the
   application `APP_KEY`.

Credentials are encrypted at rest, hidden from JSON serialization, and masked
as `abcd****5678`. Rotating a credential replaces the existing row for the same
user and exchange instead of creating a duplicate.

## Testnet

Set `TRADING_EXCHANGE_SANDBOX=true` and use testnet API keys. When a credential
has `testnet = true`, the CCXT client calls `set_sandbox_mode(true)` and passes
the `sandbox` option, so orders go to the exchange testnet rather than the live
market. Testnet keys are separate from production keys.

## Paper versus live

Paper is the default mode for every bot.

- Paper mode reads public market data only. Private calls and order placement
  through `PaperExchangeClient` throw a normalized `unsupported` error.
- Paper orders are simulated locally: a buy fills when the market price is at
  or below its limit, a sell fills when the market price is at or above its
  limit. Fills happen at the limit price and fees use `trading.paper.fee_rate`.
- Live mode uses the bot's encrypted credential and places real limit orders
  through `CcxtExchangeClient`.
- Live execution requires both `TRADING_LIVE_ENABLED=true` and per-bot
  confirmation with a passing balance check. There is no automatic fallback
  from live to paper.

## Rate limiting and retries

CCXT's built-in rate limiter is enabled. The wrapper retries only transient
`connection` and `rate_limit` errors using exponential backoff
(`retry_delay_ms * 2^(attempt-1)`). Rejected orders, authentication failures,
insufficient balance, and not-found errors are not retried.

## Failure handling

Each failed exchange operation increments the bot's consecutive failure count.
A successful operation resets it. When the count reaches
`trading.failure.max_consecutive`, the bot is paused, open orders are canceled,
and an activity log plus a critical risk event are recorded. The bot keeps its
configured mode; a live bot is never silently switched to paper. Reconciliation
conflicts after a restart pause the bot and require an explicit resume.

## Runtime commands

```bash
php artisan horizon            # process queued exchange/tick jobs
php artisan schedule:work      # drive one-second bot scheduling
php artisan migrate --force
php artisan config:show trading
```

In Docker, run these inside the `horizon` and `scheduler` services.

## Opt-in CCXT testnet integration suite

The default test suite never contacts an exchange. A separate integration test places
and cancels a small limit order on Binance testnet when explicitly enabled.

```bash
CCXT_TESTNET=1 \
BINANCE_TESTNET_API_KEY=... \
BINANCE_TESTNET_API_SECRET=... \
BINANCE_TESTNET_SYMBOL=BTC/USDT \
BINANCE_TESTNET_AMOUNT=0.0001 \
composer test:testnet
```

`CCXT_TESTNET_SYMBOL` defaults to `BTC/USDT`; `CCXT_TESTNET_AMOUNT` defaults to
`0.0001` and is raised to the market minimum when necessary. The order price is
50% below the last price so it rests without filling, and the test cancels it
immediately. Use a testnet key with spot-trading permission and no withdrawal
permission. The suite skips itself unless `CCXT_TESTNET=1` and both credentials
are present.


## Numeric normalization and trading rules

CCXT values are normalized to plain decimal strings before they enter the
domain, including very small floats that PHP would otherwise render in
scientific notation. Before an order is placed, price and quantity are rounded
to the market's price and amount precision; orders below the exchange minimum
amount or minimum notional are not sent and record an `order.min_amount` or
`order.min_notional` risk event.
