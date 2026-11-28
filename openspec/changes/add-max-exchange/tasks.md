# Tasks

Nothing here may place, cancel or modify a real order, or call a private MAX endpoint, until the phase that says so and Johnson has approved that exact action. Default CI and default compose never call MAX private endpoints. Never print or log API keys, secrets, payloads or signatures.

## 1. Phase 1: public market data, paper trading, backtests (no credentials)

- [ ] 1.1 Add fixture-based tests for MAX symbol mapping (`BTC/TWD` to `btctwd`, reverse map from a recorded `/api/v3/markets` response) and for `Rules` (`base_unit_precision`, `quote_unit_precision` including a negative value, `min_base_amount`, `min_quote_amount`, non-`active` status); verify they fail against the current `max.go`.
- [ ] 1.2 Fix `Rules` and symbol mapping in `max.go` to use public `GET /api/v3/markets`; make `Rules` carry decimal steps; verify the 1.1 tests pass.
- [ ] 1.3 Add a per-exchange market-data registry (cache, REST fallback, candle fetcher per exchange id); route `PriceSource.Last` through it and fail closed for an unregistered exchange; keep Binance behaviour byte-for-byte; verify existing marketdata tests still pass.
- [ ] 1.4 Add the MAX public feed (`trade` channel, 30 s ping, subscribe rate below 20/s, backoff, `Retry-After` on 429) and the REST fallback over `GET /api/v3/ticker`; test with a fake WebSocket server using frames copied from the MAX docs, including a stale cache falling back to REST; verify.
- [ ] 1.5 Replace the hard-coded `binance` checks in `runtime.go` (paper pause on start and reconcile, auto-resume SQL, feed-symbols SQL) with the registry predicate; add tests that a MAX paper bot runs and auto-resumes, and that an unregistered exchange is still paused with `pauseCauseUnsupportedExchange`; verify.
- [ ] 1.6 Add the MAX candle fetcher over `GET /api/v3/k` behind a fetcher interface selected by `Configuration.Exchange`; page and order as Binance does; test paging against a fake server; verify a MAX backtest runs on recorded candles.
- [ ] 1.7 Add engine config for `ENGINE_MAX_MARKET_DATA_WS_URL`, `ENGINE_MAX_MARKET_DATA_REST_URL`, defaults, and the `.env.example` and compose entries; keep `TRADING_EXCHANGES=binance` the default; verify config tests.
- [ ] 1.8 PHP: allow `max` through `TRADING_EXCHANGES`; confirm bot creation, preview, and the grid-activity candle path work for `max` using the public data client (check that the CCXT `max` id returns usable candles; if not, degrade the activity chart for `max` with the existing failure path); add feature tests with a fake client.
- [ ] 1.9 Update `docs/exchanges.md`, `engine/README.md`, `docs/engine.md`: MAX paper and backtest are supported, live is not.
- [ ] 1.10 Acceptance, run by Johnson on a non-production or paper-only bot: a TWD market (for example `BTC/TWD`) and a USDT market (`BTC/USDT`) paper bot each tick on live MAX prices for 24 hours with no unsupported-exchange pause and no private request in the engine log.

## 2. Phase 2: read-only private calls (from the NAS only)

- [ ] 2.1 Add fixture tests for v3 signing from the official docs' examples: payload JSON with `nonce`, params and `path`, base64, hex HMAC-SHA256, header names; verify they fail against the current signing (`X-MAX-TS`, concatenated string).
- [ ] 2.2 Rewrite request building in `max.go`: monotonic per-credential millisecond nonce, optional server-time offset from `/api/v3/timestamp`, GET params in the query and payload, POST and DELETE params in the JSON body and payload; verify 2.1 passes.
- [ ] 2.3 Rewrite the read endpoints against the fake and recorded fixtures: `wallet/spot/accounts` balances (`currency`, `balance`, `locked`), `wallet/spot/orders/open`, `GET /api/v3/order` by `id` and by `client_oid`, `GET /api/v3/order/trades` for fills with `fee` and `fee_currency`; map `wait`, `done`, `cancel`, `convert` with partial-fill awareness; verify.
- [ ] 2.4 Parse the real error body `{"success":false,"error":{"code","message"}}`; map 429 (with `Retry-After`), authentication, insufficient balance, not found, rejected and server errors to the existing normalized errors; add a small per-credential token bucket well under 1200 requests per minute; verify.
- [ ] 2.5 Add `engine max-verify <credential id>`: `GET` only, redacted output, refuses to build `POST` or `DELETE`; test with a fake server and with a test that the code path cannot reach a non-GET; verify output contains no key, secret, payload or signature.
- [ ] 2.6 Add `ENGINE_MAX_BASE_URL` (default `https://max-api.maicoin.com`); stop MAX inheriting `ENGINE_EXCHANGE_BASE_URL`; verify with a test that a Binance relay setting does not change MAX's host.
- [ ] 2.7 PHP: allow `max` in `ExchangeCredentialController`, hide passphrase and testnet in the credential form, force `testnet=false` for `max`; feature tests; verify keys stay encrypted and masked.
- [ ] 2.8 Acceptance, run by Johnson on the NAS with the whitelisted key: `engine max-verify` reports clock offset within a few seconds, expected permissions, quote and base balances, open order count. Record in the PR (without secrets) the observed `fee_currency` of a few recent real trades, if any exist, to settle the fee questions in section 3.1.

## 3. Phase 3: live orders (behind gates, off by default)

- [ ] 3.1 Settle fee handling from the Phase 2 evidence: if MAX charges buy fees in the base asset, subtract the fee from the tracked base quantity in the live fill path for non-quote fee currencies; add tests for quote-currency, base-currency and discounted (MAX token) fees; verify.
- [ ] 3.2 Implement place and cancel against `POST /api/v3/wallet/spot/order` (`market`, `side`, `volume`, `price`, `ord_type=limit`, `client_oid`) and `DELETE /api/v3/order`; treat both as asynchronous and confirm through `GET /api/v3/order`; resolve a rejected reuse of `client_oid` by lookup, not by retry; fake-server tests for success, timeout then lookup, duplicate id, insufficient balance, rate limit, `cancel-only` and `suspended` markets; verify.
- [ ] 3.3 Add `TRADING_MAX_LIVE_ENABLED` (default `false`) to the engine config, `config/trading.php`, `.env.example` and compose; wire it to the factory in place of `AllowUnverifiedMAX`; replace the hard `max` refusal in `LiveModeGuard` with this flag; tests for each gate order (global gate, venue gate, per-bot confirmation, balance check) pausing and cancelling as today; verify.
- [ ] 3.4 Add `ENGINE_MAX_DRY_RUN`: build, round, validate and sign the place request, log a redacted copy, record the intent, never send; test that no HTTP request leaves in dry-run; verify.
- [ ] 3.5 Update the exchange-connectivity spec tests and docs: replace "MAX proven on fakes only" with the no-testnet procedure; document the key setup (read and trade only, no withdrawals, IP whitelist = NAS WAN IP) and the rollback in `docs/exchanges.md`, `docs/security.md`, `docs/risk.md`.
- [ ] 3.6 Acceptance on the NAS, each step needs Johnson's explicit yes naming the bot, market, size and price:
  - [ ] 3.6.1 Run one MAX live bot in dry-run for at least 24 hours on real prices; review the logged intents (rounding, min notional, client id length and characters, sizing).
  - [ ] 3.6.2 First real order: a single limit order for the smallest allowed size on a market Johnson chooses, priced far from the market so it rests; confirm it through `max-verify` and the MAX web UI; cancel it and confirm the cancel.
  - [ ] 3.6.3 A small bot (capital at or just above the market minimum) for a short supervised window, then review fills, fees, balances and the reconciliation result against the MAX account history.
  - [ ] 3.6.4 Rehearse the kill switch and the cancel-all path with that bot, including the engine being stopped mid-order.

## Open questions for Johnson

- Which markets first: TWD quotes (`btctwd`, `usdttwd`) or USDT quotes (`btcusdt`, `ethusdt`)? TWD quotes mean the bot's quote asset and risk limits are in TWD.
- Which strategies on MAX first (all four, or a subset)?
- Is the NAS WAN IP `122.116.249.99` static? If it can change, the whitelist breaks silently.
- What does the relay at `https://122.116.249.99:8443` do, and is it needed at all? The repo does not reference it and the engine runs on the NAS. If the engine ever runs elsewhere, does the relay forward arbitrary paths, query strings, bodies and `X-MAX-*` headers unchanged?
- Does Johnson pay MAX fees with the MAX token (discounted fees), and which fee tier applies? Paper and backtest fee settings should match.
- Is paper trading on MAX for a few days acceptable before Phase 2, and should Phase 2 and 3 be separate PRs? (This proposal assumes yes to both.)
- Is a hard per-bot notional cap for MAX live bots wanted, beyond the existing risk limits (for example, refuse any MAX live bot above a configured quote amount)?
- Who gives the explicit approval for the first real order, and how (chat message naming bot, market, size and price)?
