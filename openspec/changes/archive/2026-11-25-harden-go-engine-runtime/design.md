# Design

## Context

The hybrid stack is already on `main`: Laravel is the management plane, and the Go engine owns ticks, paper fills, risk, command consumption, backtests, and the heartbeat. PHP trading workers were stopped in favor of a direct swap. Three gaps remain.

1. A Binance public WebSocket client already lives in `engine/internal/marketdata`, with reconnect, gap recovery, and REST fallback. Production ticks ignore it. `Runtime` calls `publicMarket.Last` (`engine/internal/runtime/tick.go`) and hits `https://api.binance.com` by default on every tick. The feed only starts when `ENGINE_MARKET_DATA_SYMBOLS` is set, and even then the cached price is not the runtime source.
2. `app/Strategies/*` are still bound in `AppServiceProvider`. `GridPlanEvaluator` still calls PHP `strategy->onTick` for setup preview. After cutover, that is a second evaluator.
3. `engine/main.go` and `runtime.go` fail closed on live: they pause a live bot and make no private call. Specs already require live place/cancel, testnet, credential decrypt-in-memory, rate-limit normalization, risk gates, and no live-to-paper fallback. Laravel still has `CcxtExchangeClient` plus `CancelBotOrders` for the emergency path when the heartbeat is missing or command publish fails. Those emergency jobs stay.

Johnson's instruction for this change is to design real live execution in the Go engine. The first applied live phases run only against testnet (or fakes). A real-money live account is out of bounds unless he gives an explicit yes for that exact action.

## Goals / Non-Goals

**Goals:**

- Use the existing public market-data WebSocket as the primary tick price source, subscribed to the symbols of currently running bots, with a last-price cache, REST fallback, and a staleness bound.
- Make the Go strategy implementations the only evaluator used for ticks and for setup plan preview / create-update validation.
- Implement a Go private exchange client for Binance and MAX, including place, cancel, and reconcile, behind `TRADING_LIVE_ENABLED` (default false; empty is false).
- Prove the live path on fakes in CI, and on Binance testnet only in an opt-in suite. Prove the MAX adapter on fakes only. Keep emergency `CancelBotOrders`. Never fall a live bot back to paper.

**Non-Goals:**

- Changing, replacing, or redesigning the NAS relay or any existing HTTP proxy used to reach an exchange. The Go client honors the same transport settings.
- Publishing the engine preview endpoint on the public nginx / Internet surface.
- Starting or stopping laptop Horizon or the scheduler as part of this change.
- Deleting, resetting, or merging `origin/hybrid-engine`.
- Implementing unlisted CCXT venues beyond the configured allowlist.
- A default apply task that soaks a real-money production account, a MAX testnet or MAX live proof, or flipping `TRADING_LIVE_ENABLED=true` in production compose.
- Reworking paper-mode fills, command streams, heartbeat TTL, or the already-ported risk rules except to invoke them immediately before a live place.

## Decisions

### 1. WebSocket-backed ticks with a cached last price

The engine already has a public feed. This change wires it to `Runtime`.

- On start, and whenever a bot starts or stops, the feed's symbol set is the union of running (and starting) bots' symbols for exchanges the feed supports. Idle engines subscribe to nothing.
- `Runtime.Last` reads the cached last price for `(exchange, symbol)`. If the cache is missing or older than the staleness bound, it takes a public REST snapshot, records the degraded or stale state without secrets, and uses that snapshot for the tick. A persistently stale cache does not invent a price.
- Default staleness bound: 15 seconds, or half of `TRADING_TICK_INTERVAL` when that is larger, overridable by config (for example `ENGINE_MARKET_DATA_STALE_AFTER`). The existing feed already has quiet-period / gap recovery; this bound is the last line before a tick uses a price.
- Public data only. No signed market-data endpoints. Multi-exchange: Binance uses the existing WS client; a venue without a WS client may stay on REST until a feed exists, but the cache and staleness bound still apply.
- Tests use a fake feed and `httptest`, not a live socket.

### 2. Plan preview through an internal engine HTTP endpoint

Options considered:

| Option | Fit | Drawback |
| --- | --- | --- |
| A. Synchronous internal HTTP evaluate endpoint on the engine | Same Go code as runtime; request/response matches the UI; no extra stream protocol | Needs a tightly scoped internal listener |
| B. Redis stream request/reply | Reuses the command bus | Poor latency and timeout behavior for a form preview; couples UI to XREAD/XADD; harder to keep preview and save on one round trip |
| C. Pure PHP grid calculator (no `onTick`) | Avoids an engine hop | Drifts from Dynamic, Trailing, and Martingale (and from future Go-only behavior). Violates "preview and save agree" the moment create/update also needs Go, or silently disagrees if it does not |

**Recommendation: option A.** Add a loopback / Docker-network HTTP handler, for example `POST /v1/plan/evaluate`, that accepts a non-persisted setup (strategy, symbol, exchange, grid and risk fields, optional mark price) and returns levels, totals, warnings, and the market data used. Laravel's preview action and the create/update validation path call the same endpoint. If the engine is unreachable, both fail with a clear user-visible error and persist nothing. A second, approximate PHP calculator is rejected because it would reintroduce drift.

The endpoint is not published through public nginx and is not published to the host. Bind it to the compose service network or loopback only, with Laravel as the only caller. If the engine HTTP port remains published to the host, v1 MUST require a shared internal token; network isolation alone is not enough in that case.

Public market data may be used to anchor a preview. The endpoint MUST NOT make private (credentialed) exchange calls.

PHP `app/Strategies/*`, their `AppServiceProvider` bindings, and the `GridPlanEvaluator` `onTick` path are deleted once the Laravel client and tests are green.

### 3. Live execution in the Go engine, testnet first

The Go engine becomes the private exchange client for live bots. Laravel continues to encrypt credentials at rest and to confirm a paper-to-live mode change. The engine decrypts in memory with `APP_KEY` / `previous_keys` as already specified, never persists plaintext, and never logs keys, secrets, or signatures.

**Client shape.** A small Go interface equivalent to the PHP `ExchangeClient` (place limit, cancel, fetch order, open orders, trading rules, balance, normalized errors, rate-limit backoff). Implement both a Binance adapter (including official testnet/sandbox base URLs already implied by `TRADING_EXCHANGE_SANDBOX` and credential `testnet`) and a MAX adapter now. There is no requirement to vendor a full CCXT port; behavior and error classes must match the existing PHP/CCXT allowlisted usage closely enough that risk, reconcile, and UI stay stable. The MAX adapter is proven with fakes only in this change.

**Transport.** If the deployment already reaches an exchange through a NAS relay, HTTP proxy, or custom base URL, the Go client MUST honor those existing settings for non-testnet traffic. This change does not add, remove, or redesign that relay. Testnet or sandbox mode MUST use official testnet base URLs and MUST fail closed if the transport would rewrite them to a production host. If the existing relay cannot preserve testnet URLs, the opt-in Binance testnet suite MUST NOT use that relay.

**Gate.** `TRADING_LIVE_ENABLED` defaults false. `parseBool` on empty stays false. Production compose stays `"false"`. When the gate is closed, a live bot is paused (or refused at start), a risk event is recorded, and the bot is not converted to paper. When the gate is open, a live bot still needs sufficient exchange balance before start, and every place is preceded by the existing risk gates (exposure, daily loss, kill switch, open-order caps, and any other already-ported checks).

**Idempotency.** Every live place carries a stable client order ID derived from the order intent (bot, level or strategy key, side, and a generation). The generation bumps only when a new intent is created after the previous order on that key is filled or canceled; it MUST NOT bump on an in-flight retry. Identifiers MUST satisfy the selected venue's length and character rules (for example Binance `clientOrderId`). The engine persists the ID durably before any network place call. A retry or startup resume looks up the intent by that ID, including intents that still have no exchange order ID, and MUST NOT mint a new ID for the same intent. After an uncertain place (timeout or unknown outcome), the engine queries the exchange by client order ID, or follows a documented venue duplicate-ack path, before sending another place. A completed client order ID is never reused for a new intent.

**Reconcile.** Private startup and periodic reconcile run for live bots only. Paper bots reconcile locally and MUST NOT make private exchange calls. Live reconcile happens on engine start, before new ticks, and periodically using `TRADING_ORDER_SYNC_SECONDS` (default 5s) or a documented engine equivalent, subject to per-venue rate-limit budgets and backoff; a rate-limit response is respected and is not retried immediately. Reconcile never invents fills. It applies partial fills to inventory and leaves the remainder open, updates or removes orders the exchange reports as fully filled or canceled, and surfaces ambiguities as errors. Intents that have a client order ID but no exchange order ID are looked up by client order ID before any new place.

**Emergency cancel.** Laravel `CancelBotOrders` / `CancelOrder` remain the emergency path when a user hits kill-switch or stop and the heartbeat is missing or expired, or command publish fails. The engine still consumes in-band `order.cancel` and `kill_switch` when it is healthy.

**Phased rollout.**

1. Interface + fakes + closed-gate tests (CI, no private network calls), including the MAX adapter.
2. Binance testnet against official sandbox endpoints, behind an opt-in suite (a Go equivalent of today's `CCXT_TESTNET=1`), skipped by default and not enabled by default verify even if a developer environment already exports the flag. The suite hard-fails unless the resolved base URL is a known Binance testnet host and sandbox or credential `testnet` is enabled; production keys or production URLs are refused, not merely documented. This is the first and only exchange soak in this change. There is no MAX testnet or MAX live proof.
3. Real-money live is not an apply task. It requires Johnson's explicit yes for that exact action, the gate flipped only in the environment he names, and a follow-up soak he authorizes.

No task in `tasks.md` may run against a real-money live account without that yes.

### 4. Minor cleanups in the same change

- Replace the stale `engine/main.go` comment that still talks about an in-process `commandBus` / `backtestBus` once commands already route to runtime.
- Rename leftover `heartbeat.NewStub` (the implementation is a real Redis heartbeat) and drop the "until the tick loop lands" comment.
- After a usage grep, delete `TickResult`, `ReconcileResult`, and `CapacityReport` if they have no remaining callers.
- Remove engine-owned columns from `Bot` `$fillable`: `status`, `last_error`, inventory and PnL fields, `consecutive_failures`, `last_tick_at`, and `last_successful_tick_at`. Laravel-owned fields, including `desired_status` and configuration, stay fillable. Engine-owned keys inside `settings` (`mark_price`, `recent_prices`, `daily_loss_baseline`) remain protected by the existing merge rules.

## Risks / Trade-offs

- **[Testnet ≠ production]** → Mitigation: treat testnet as proof of wiring and idempotency, not as a production soak. Real-money remains a separately approved action. Document known testnet quirks (balances, filters, rate limits).
- **[MAX has no soak in this change]** → Mitigation: ship the MAX adapter and prove it on fakes only; do not call MAX testnet or MAX production. A later, separately approved change can add a MAX proof.
- **[Engine down blocks preview and save]** → Mitigation: this is preferred over a drifting PHP calculator. Surface a clear "engine unavailable" error. The engine is already a required runtime process after cutover.
- **[Internal HTTP surface]** → Mitigation: do not publish the evaluate endpoint on public nginx or the host; require an internal token if the engine port stays published to the host.
- **[Relay or proxy rewrites testnet to production]** → Mitigation: testnet mode uses official testnet URLs and fails closed if transport would rewrite them; the opt-in suite does not use a relay that cannot preserve those URLs.
- **[Stale or wrong WS prices]** → Mitigation: staleness bound plus REST snapshot; fail the tick rather than trade on a too-old price.
- **[Idempotent place still fills twice if the ID scheme is wrong]** → Mitigation: persist the client order ID before the network call; look up by that ID, including in-flight intents with no exchange ID, before retry or startup place; bump generation only after fill or cancel; respect venue ID format; cover these in fake-exchange tests.
- **[Reconcile interval hits rate limits]** → Mitigation: keep the default interval but apply per-venue budgets and backoff; test that a rate-limit error is not retried immediately.
- **[Emergency cancel vs engine cancel races]** → Mitigation: keep the existing heartbeat-gated Laravel path; reconcile treats already-canceled orders as success.

## Migration Plan

Apply in the isolated worktree (or a later apply worktree), not in the in-progress main checkout.

1. Land WebSocket-backed ticks and tests against fakes. Paper bots should behave as today, with fewer public REST calls in the happy path.
2. Land the evaluate endpoint and switch Laravel preview / validation to it; delete PHP strategies; keep Pest preview cases green.
3. Land the live client, gates, client order IDs, and reconcile against fakes. Keep `TRADING_LIVE_ENABLED=false` in default and production compose.
4. Add the opt-in Binance testnet suite with hard URL and credential guards. Do not enable it in CI or default verify. Do not point it at a real-money key or a production-rewriting relay.
5. Update docs and dashboard copy so live is described as implemented, testnet-first, and production-gated.
6. Run the minor cleanups and the full verify set (`openspec validate … --strict`, Go tests, Pest, PHPStan).

Rollback: revert the change branch. A closed live gate (`TRADING_LIVE_ENABLED=false`) remains the runtime kill for live orders even after the code lands. Emergency cancel continues to work. Preview rollback would temporarily require restoring PHP strategies, so the preview cut should be one revert-friendly commit if possible.

