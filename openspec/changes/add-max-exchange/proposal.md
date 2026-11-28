# Proposal

## Why

Johnson holds a MAX Exchange (MaiCoin, Taiwan) account and wants the bot to trade there, starting with TWD-quoted and USDT-quoted spot markets. MAX is not a stub in the repo: `engine/internal/exchange/max.go` exists, the `max` id can be added to `TRADING_EXCHANGES`, and the specs promise per-bot exchange routing. In practice none of it works against the real venue:

- `max.go` was written against fakes and does not match the official MAX API v3. It signs `timestamp+method+path+query+body` with an `X-MAX-TS` header, while v3 sends `X-MAX-ACCESSKEY`, `X-MAX-PAYLOAD` (base64 of a JSON body holding `nonce`, the request params and `path`) and `X-MAX-SIGNATURE` (hex HMAC-SHA256 of the payload string). It calls `POST /api/v3/orders`, `GET /api/v3/orders?state=wait`, `GET /api/v3/wallet/accounts` and `GET /api/v3/info/markets`; v3 uses `POST /api/v3/wallet/spot/order`, `GET /api/v3/wallet/spot/orders/open`, `GET /api/v3/wallet/spot/accounts` and public `GET /api/v3/markets`. It reads `min_amount`, `min_total`, `price_precision` and `amount_precision` for symbol rules, but v3 publishes `min_base_amount`, `min_quote_amount`, `base_unit_precision` and `quote_unit_precision`. It hard-codes the fee currency to `USDT` and fails to parse the real error body `{"success":false,"error":{"code","message"}}`.
- The engine's market data is Binance-only (`marketdata.NewPriceSource(..., "binance", ...)`, `FeedSymbols` selects `exchange = 'binance'`, the backtest candle fetcher refuses anything else), and the runtime pauses every non-Binance paper bot with "Exchange max is not supported by the Go runtime for paper bots yet". The specs already require paper data from the bot's own exchange, so this is also a code/spec gap.
- Live MAX is unreachable by design: the factory requires `AllowUnverifiedMAX`, which `main.go` never sets, and `LiveModeGuard` throws for `max`. The spec says MAX "SHALL be proven with fakes only".
- MAX has **no testnet**. Binance's "testnet first" safety story does not apply, so we need a different, explicit one before a single real order is sent.
- The MAX API key is IP-whitelisted to the NAS public IP (`122.116.249.99`) and has withdrawals off. Calls from anywhere else (laptop, CI, a cloud box) are rejected, so verification has to run from the NAS stack.

## What Changes

- **Correct the existing MAX adapter, don't add a new abstraction.** `exchange.Client` and `exchange.Factory` already are the adapter boundary. Rewrite `max.go` against the official v3 docs (signing, nonce, endpoints, order states, error shape, symbol rules, per-trade fee currency) and keep fake-based unit tests plus recorded-fixture tests built from the public docs.
- **Make market data exchange-aware.** Replace the single-exchange `PriceSource` and Binance-only `FeedSymbols` with a small per-exchange source registry. Add a MAX public feed (`wss://max-stream.maicoin.com/ws`, `trade` channel, with `GET /api/v3/ticker` as REST fallback) and a MAX candle fetcher (`GET /api/v3/k`) for backtests.
- **Remove the hard-coded `binance` checks in the runtime** (paper pause, auto-resume filter, feed symbol query) in favour of one `paperSupported(exchange)` predicate backed by the registry.
- **Add MAX symbol rules** (precision, possibly negative; minimum base and quote amount; market status `active`/`cancel-only`/`suspended`) so the existing round-to-tick/step and min-amount/min-notional path in `live.go` and the paper path work for TWD and USDT quotes.
- **Credentials and form.** Allow `max` in the credential controller and the bot form (no passphrase, no testnet toggle for MAX), keep keys encrypted exactly as today.
- **A MAX-specific live gate and safety procedure.** Add a venue gate `TRADING_MAX_LIVE_ENABLED` (default `false`, replaces the never-set `AllowUnverifiedMAX` plumbing) on top of `TRADING_LIVE_ENABLED` and the existing per-bot typed confirmation. Add a read-only `engine max-verify` command and a dry-run mode that signs and validates an order without sending it. Define a small-size first-order checklist that needs Johnson's explicit yes.
- **Phased delivery**, each phase a separate PR and a separate go/no-go:
  1. Public MAX market data, paper trading, backtests. No credentials.
  2. Read-only private calls (balances, open orders, order lookup, trades) from the NAS, plus `max-verify`.
  3. Live orders, behind the gates above.
- Update `docs/exchanges.md`, `docs/security.md`, `engine/README.md`, `.env.example`, `docker-compose.prod.yml`.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `exchange-connectivity`: MAX market data, symbol rules, v3 private API behaviour, the MAX live gate and its no-testnet safety procedure, MAX transport; replaces "MAX proven on fakes only".
- `bot-runtime`: MAX paper bots run (and auto-resume) like Binance paper bots; unsupported means "no registered market-data source", not "not Binance".
- `backtesting`: Backtests can read MAX public candles.
- `risk-controls`: MAX live orders require the venue gate, the global gate and the per-bot confirmation; a failing check pauses and cancels as today.

## Impact

- Affected code: `engine/internal/exchange/{max.go,factory.go,rules}`, `engine/internal/marketdata/*`, `engine/internal/backtest/fetcher.go`, `engine/internal/runtime/{runtime.go,live.go}`, `engine/internal/config`, `engine/main.go`, `app/Http/Controllers/ExchangeCredentialController.php`, `app/Domain/Risk/LiveModeGuard.php`, `config/trading.php`, bot and credential Vue pages, docs and compose files.
- No new database table. Phase 1 needs no migration. Phase 3 may need none either: the credential row already stores key and secret, and `passphrase` is nullable.
- No change to Binance behaviour; Binance remains the default exchange.
- Production stays `TRADING_LIVE_ENABLED=false`. Nothing in Phase 1 or 2 can place an order. Phase 3 does not ship enabled.
- Risk: real-money trading on an exchange we cannot test against. Mitigated by phase gating, the dry-run mode, read-only verification, small first order, and Johnson's explicit approval at each step.
