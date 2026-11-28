# Design

## Context

See `proposal.md` for motivation. Relevant facts about the repo today:

- Go engine: `exchange.Client` (place, cancel, lookup by client order id, open orders, balances, `Rules`) and `exchange.Factory` choose a client per bot. Binance is real; `max.go` is fake-proven only and gated by `Factory.AllowUnverifiedMAX`, which `main.go` never sets. The factory rejects sandbox/testnet credentials for MAX and passes the global `ENGINE_EXCHANGE_BASE_URL` override to whichever exchange is built.
- Live order path (`runtime/live.go`) already does: round price and quantity to the venue's step before deriving the deterministic client order id, min amount and min notional checks, persist the id before the network call, look up by client id before an uncertain retry, and adopt a duplicate id. `liveClientIDMax` is 36, which equals MAX's `client_oid` limit.
- Market data is Binance-shaped end to end: `marketdata.Feed` (WS `@trade`/`@bookTicker`), `RESTClient` (`/api/v3/ticker/24hr`), `PriceSource` (rejects any exchange other than the one it was built for), `backtest.BinanceCandleFetcher`, and SQL in `runtime.go` selecting `exchange = 'binance'` for feed symbols and auto-resume. `runtime.go` pauses non-Binance paper bots.
- Paper mode never makes private calls. Paper fills happen at tick time at the limit price.
- PHP side: `config('trading.exchanges')` allowlist and the bot form picker already handle several exchanges; `ExchangeCredentialController` only allows `binance`; `LiveModeGuard` throws for `max`; CCXT is the PHP public-data client.
- The production stack (`docker-compose.prod.yml`) runs on the QNAP NAS, so the engine's outbound IP is the NAS WAN IP. The repo contains no reference to the relay at `https://122.116.249.99:8443`; the only transport hook is the global `ENGINE_EXCHANGE_BASE_URL`, which is a single URL and is meant for Binance.

MAX API v3 facts this design relies on (from the official swagger and docs, checked 2026-11-28):

- Base `https://max-api.maicoin.com`. Public: `GET /api/v3/markets`, `/ticker`, `/tickers`, `/depth`, `/trades`, `/k`, `/timestamp`. Private: `GET /api/v3/info`, `GET /api/v3/wallet/spot/accounts`, `POST /api/v3/wallet/spot/order`, `GET /api/v3/wallet/spot/orders/open`, `GET /api/v3/order` (by `id` or `client_oid`), `DELETE /api/v3/order`, `GET /api/v3/order/trades`, `DELETE /api/v3/wallet/spot/orders` (cancel all, optionally per market).
- Auth: headers `X-MAX-ACCESSKEY`, `X-MAX-PAYLOAD`, `X-MAX-SIGNATURE`. Payload is base64 of JSON `{"nonce": <ms>, ...params, "path": "/api/v3/..."}`; signature is hex HMAC-SHA256 of the base64 payload string keyed with the secret. GET params go in the query string, POST/DELETE params in the JSON body (the same values must be in the payload). Nonce must be within 30 s of server time and cannot be reused.
- `client_oid` is at most 36 characters and cannot be reused within 24 hours.
- Order creation and cancellation are asynchronous; confirm with `GET /api/v3/order` or the private WebSocket.
- Order `state` is `wait`, `done`, `cancel` or `convert`. A `cancel` may carry `executed_volume > 0` (partially filled, then cancelled); a `wait` may carry `executed_volume > 0` (partial fill).
- Markets expose `status` (`active`, `cancel-only`, `suspended`), `base_unit_precision` and `quote_unit_precision` (can be negative), `min_base_amount` and `min_quote_amount`. Examples fetched from the public endpoint: `btctwd` base precision 8, quote precision 1 (price tick 0.1 TWD), min base 0.0001, min quote 250 TWD; `usdttwd` base 2, quote 3, min base 8, min quote 250; `btcusdt` base 6, quote 2, min base 0.0001, min quote 8 USDT.
- Rate limits: 1200 requests/min per IP for public, per account for private. WebSocket: request messages limited to 20/s and 200/min, 600 new connections/hour per IP, ping at least every 130 s. A 429 carries `Retry-After`; repeated violations extend a ban.
- Public WebSocket `wss://max-stream.maicoin.com/ws`: subscribe with `{"action":"sub","subscriptions":[{"channel":"trade","market":"btctwd"}]}`; frames use short keys (`c` channel, `e` snapshot|update, `M` market, `t[].p` price, `t[].T` ms time).
- There is no MAX testnet.

## Goals / Non-Goals

**Goals:**

- A MAX paper bot and a MAX backtest behave like their Binance counterparts, with no credentials.
- The MAX adapter matches the official v3 API, with offline tests that would have caught the current signing and endpoint mistakes.
- A staged, reversible path to live MAX orders where each stage has its own verification and an explicit human approval.
- Reuse what exists: `exchange.Client`, `Factory`, the live order path, the allowlist, per-bot routing, the live confirmation.

**Non-Goals:**

- No new exchange abstraction layer, no CCXT in the engine, no multi-exchange arbitrage.
- No margin/M-wallet, convert, stop or post-only order types, no withdrawals, no sub-account support. Spot limit orders only.
- No private WebSocket in the first live version; reconciliation stays REST-based like Binance.
- No change to strategy logic, risk thresholds or Binance behaviour.
- No enabling of live trading in production as part of this change.

## Decisions

### Reuse `exchange.Client`; rewrite `max.go`, don't wrap it

The interface already covers what the live path needs. The work is to make `max.go` true to v3, not to add an adapter layer. Specific corrections: v3 signing; a monotonic per-credential nonce (milliseconds, never repeated, bumped when the clock stalls); optional one-time server-time offset from `GET /api/v3/timestamp` (seconds) so a skewed NAS clock fails loudly rather than as an opaque 401; the endpoints listed above; `state` mapping with partial-fill awareness (`wait` with executed volume is partially filled, `cancel` with executed volume is cancelled with a partial fill, never plain open or plain cancelled); the real error shape with MAX error codes mapped onto the existing normalized errors (rate limit on 429 and the `Retry-After` header, authentication, insufficient balance, not found, rejected); the per-fill fee and fee currency taken from `GET /api/v3/order/trades` instead of a hard-coded `USDT`.

Alternative: delete `max.go` and generate a client from the swagger file. Rejected: the interface surface is small, and generated code adds a dependency and a review burden for about six endpoints.

### Symbol mapping and rules from the public markets list

Bot symbols stay `BASE/QUOTE`. The MAX id is the lowercase concatenation (`BTC/TWD` becomes `btctwd`). The reverse mapping is built from `GET /api/v3/markets` (`base_unit`, `quote_unit`) once at client creation and refreshed on a long interval, so we never guess where a base ends.

`Rules` come from the same list: `AmountPrecision` is `base_unit_precision`, `PricePrecision` is `quote_unit_precision`, with the step as `10^-precision` (a negative precision gives a step above 1; `Rules` must carry steps as decimals, not as a place count only), `MinAmount` is `min_base_amount`, `MinNotional` is `min_quote_amount`. A market whose status is not `active` makes new orders fail closed (`cancel-only` still allows cancels). Rules are cached per symbol with a short TTL and are the only place precision comes from, so the existing rounding in `live.go` and the paper path need no MAX-specific code.

### Per-exchange market-data sources

Replace the single `PriceSource` with a registry keyed by exchange id. Each entry provides a `Snapshotter` (WS-fed cache), a REST fallback, and a candle fetcher. `PriceSource.Last(ctx, exchange, symbol)` looks up the registry entry and fails closed if none exists. The runtime's "paper-supported" test becomes `registry.Has(exchange)`. Binance registers as today. MAX registers when its URLs are configured, with defaults `wss://max-stream.maicoin.com/ws` and `https://max-api.maicoin.com`.

The MAX feed subscribes to the `trade` channel per market and uses the last trade price as the tick price, matching how the Binance feed uses `@trade`. `GET /api/v3/ticker?market=` (`last`) is the REST fallback and is used when the cache is missing or stale. Order-book depth is not subscribed; paper fills use the last price today and this change does not alter that. The feed sends a ping every 30 s, keeps subscribe messages well below 20/s, reconnects with the existing backoff, and treats a 429 on connect as a ban: it honours `Retry-After` and stays idle until then. Feed symbols become the union over supported exchanges instead of `exchange = 'binance'`.

Alternatives: poll REST only (simpler, but 60 s ticks make the stale-cache bound of 15 s fall back to REST on every tick, and 1200/min is shared by all MAX bots); CCXT in the engine (not available in Go). WebSocket with REST fallback mirrors the Binance design and keeps one mental model.

### Backtests read MAX candles

Introduce a candle-fetcher interface chosen by `Configuration.Exchange`, with the Binance fetcher unchanged and a MAX fetcher over `GET /api/v3/k` (`period` in minutes, `limit`, `timestamp` in seconds). Paging, rate limiting, and ascending order follow the existing historical-data requirement. MAX candle limits per request are checked against the docs when implementing; the fetcher pages in the same way as Binance's.

### Runtime gates

The three hard-coded `binance` checks in `runtime.go` (paper pause on start, pause on reconcile, auto-resume SQL) and the feed-symbols SQL use the registry. A bot on an exchange with no registered source is still paused with `pauseCauseUnsupportedExchange`, so the failure mode for an unknown exchange is unchanged.

### One more live gate for MAX, and a dry run

Live MAX orders require, in order: `TRADING_LIVE_ENABLED=true` (existing), `TRADING_MAX_LIVE_ENABLED=true` (new, default false in `.env.example`, compose and Go config; this replaces `AllowUnverifiedMAX`, which stays unexported by `main.go` unless the new flag is true), and the per-bot typed confirmation `"<name> on max"` (existing `LiveModeGuard` flow). A bot that fails a check is paused with the live-gate cause and its resting orders are cancelled, as today. `LiveModeGuard`'s hard `max` refusal is replaced by the same flag read from `config('trading.max_live_enabled')`.

Because there is no testnet, add two tools that keep real money out of the loop for as long as possible:

- `engine max-verify` — a read-only CLI subcommand. It uses the stored credential for a named credential id, performs only `GET` calls (`/timestamp`, `/info`, `/wallet/spot/accounts`, `/wallet/spot/orders/open`, public `/markets`), prints non-secret results (clock offset, permission summary, balances for the bot's quote and base asset, open order count, whether the call came from a whitelisted IP), and refuses to build a `POST` or `DELETE` request. It is the Phase 2 acceptance check and is run on the NAS.
- `ENGINE_MAX_DRY_RUN=true` — builds, rounds, validates and signs the exact place request the live path would send, logs a redacted copy (no key, signature or payload), records an order intent and then stops before the network call. It proves symbol rules, min notional, client id format and the sizing the strategy would use against real market data without an order.

Both are inert in CI. The first-live-order checklist is in `tasks.md` and needs Johnson's explicit yes naming the bot, market, size and price.

### Transport and the IP whitelist

The engine container runs on the NAS, so MAX calls from the production stack leave from the NAS WAN IP with no relay. This design therefore needs no relay change and does not touch one. Two consequences:

- `ENGINE_EXCHANGE_BASE_URL` is a single global override today, meant for Binance. MAX must not inherit it. Add `ENGINE_MAX_BASE_URL` (default `https://max-api.maicoin.com`). If a relay is ever needed, it is configured only there. MAX signs the request path inside the payload but not the host, so a transparent HTTPS relay that preserves path, query, body and the `X-MAX-*` headers works; a relay that rewrites paths or strips headers does not. TLS verification stays on (an IP-literal relay needs a matching certificate or an explicit pinned CA, never `InsecureSkipVerify`).
- The whitelisted IP is the NAS WAN IP. If the ISP changes it, every private MAX call fails with an authentication error until the whitelist is updated. `max-verify` surfaces that clearly, and a MAX authentication failure pauses the bot like any other repeated failure. The existing testnet transport isolation requirement (fail closed if testnet would hit a production host) is unchanged and does not apply to MAX, which has no testnet.

Laptop, CI and cloud runners cannot run private MAX tests; the proofs for them are fakes and recorded fixtures.

### Fees and inventory

`live.go` currently reduces quote inventory by a fill's fee only when the fee currency is the quote asset, and records but ignores other fee currencies. MAX returns `fee` and `fee_currency` per trade, and a MAX fee may be charged in the base asset on buys (to be confirmed from real trade records in Phase 2) and may be discounted when the user pays with the MAX token. If a buy fee is taken from the base asset, the bot's tracked base quantity would be larger than what the account holds and a later sell of the full quantity could be rejected for insufficient balance. Phase 2 therefore records real `fee_currency` values from read-only trade history, and Phase 3 does not start until the inventory accounting handles a base-asset fee (subtract the fee from the bought quantity) or the data shows it is not needed.

### Credentials

Same storage and encryption as Binance. MAX uses an access key and a secret; `passphrase` stays null. The credential form for `max` hides passphrase and testnet, and the server forces `testnet=false` for it. The engine rejects a MAX credential with `testnet=true` (already true in the factory). Key guidance in `docs/exchanges.md`: read and trade permissions only, withdrawals off, IP whitelist set to the NAS WAN IP.

## Risks / Trade-offs

- **No testnet, real-money-first.** Mitigation: three phases, `max-verify`, dry run, venue gate default off, small first order, explicit approval, and the same pause-and-cancel behaviour on any failure.
- **Hand-written signing could still be wrong.** Mitigation: fixture tests built from the official docs' examples, a Phase 2 read-only check against the real API, and the dry run for the order body, all before any `POST`.
- **Asynchronous create/cancel.** A place can succeed server-side while the HTTP call times out. Mitigation: the existing persist-client-id, lookup-before-retry, and duplicate-adoption flow; MAX's "client_oid reuse within 24 h is rejected" means a retry with the same id after a real success returns an error that must be resolved by lookup, not treated as a failure.
- **Rate limit sharing.** All MAX bots share 1200/min per account and per IP. Mitigation: existing per-bot `Retry-After` handling plus a small per-credential token bucket in the MAX client.
- **Fee currency mismatch** (see above).
- **Egress IP drift.** Mitigation: surfaced by `max-verify` and by the failure handling; documented in the runbook.
- **WebSocket bans.** One shared connection per engine, conservative subscribe rate, honour `Retry-After`.

## Migration Plan

Phase 1 ships with `max` addable to `TRADING_EXCHANGES` and MAX public data on; it contains no private code path reachable in production. Phase 2 adds the read-only commands and runs them on the NAS by hand. Phase 3 ships with `TRADING_MAX_LIVE_ENABLED=false` and `ENGINE_MAX_DRY_RUN=true` as the documented defaults; flipping either is a deliberate operator step with Johnson's approval. Rollback at any phase is: set `TRADING_MAX_LIVE_ENABLED=false`, remove `max` from `TRADING_EXCHANGES`, and let the engine pause and cancel MAX live bots.

## Open Questions

See the list at the end of `tasks.md`; these need Johnson's answers before Phase 3.
