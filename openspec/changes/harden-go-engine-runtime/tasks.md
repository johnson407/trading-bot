# Tasks

Apply this change in an isolated worktree. Do not switch or edit `~/Repos/trading-bot` if it is on another in-progress branch.

**Guardrails (every task):**

- Paper, fakes, and public market data only, unless a task explicitly names testnet.
- `TRADING_LIVE_ENABLED` stays default false. Do not set it to true in compose or in a shared `.env`.
- **No task may run against a real-money live account without Johnson's explicit yes for that exact action.** Testnet is not a real-money account; production keys and production base URLs are. If a later task would need a real-money call, stop and wait for that yes. There is no such task in this list.
- Do not print, commit, or log secrets, `.env` values, or `APP_KEY`.
- Do not start or stop laptop Horizon or the scheduler as part of this change.
- Do not delete, reset, or merge `origin/hybrid-engine`.
- Do not change the NAS relay (or any existing proxy) beyond honoring it as transport.
- Do not push unless asked.

## 0. Guardrails and baseline

- [ ] 0.1 Confirm the apply checkout is isolated from the main in-progress worktree, on a branch from current `origin/main` (or this proposal branch), and that `openspec validate harden-go-engine-runtime --strict` is the starting command after edits.
- [ ] 0.2 Record that verification uses fakes and, only when opted in, Binance testnet. No real-money credentials are wired into CI or default compose.

## 1. WebSocket-backed ticks

- [ ] 1.1 Drive the existing public market-data feed's symbol set from running (and starting) bots: subscribe on start, update on bot start/stop, and unsubscribe symbols that no running bot needs.
- [ ] 1.2 Change `Runtime` last-price reads to use the cached feed price for `(exchange, symbol)`.
- [ ] 1.3 When the cache is missing or older than the decided staleness bound (15 seconds, or half of `TRADING_TICK_INTERVAL` if larger, overridable by config), take a public REST snapshot, record the degraded or stale state without secrets, and fail the tick rather than trade on a too-old price if no snapshot is available.
- [ ] 1.4 Keep private credentials out of all market-data paths. Venues without a WebSocket client may stay on REST, still through the same cache and staleness bound.
- [ ] 1.5 Add Go tests with a fake feed and `httptest` covering subscribe/unsubscribe, cache hit, stale cache + REST snapshot, and REST fallback when the socket cannot connect. Do not use live exchange credentials.
- [ ] 1.6 Update engine/runtime docs to describe the cached feed as the tick price source.

## 2. Plan preview via the engine; remove PHP strategies

- [ ] 2.1 Add an internal engine HTTP endpoint (for example `POST /v1/plan/evaluate`) that evaluates a non-persisted setup with the Go strategy implementations and returns levels, totals, warnings, and any public market data used. It MUST NOT make private exchange calls and MUST NOT persist bots, grids, orders, or risk limits.
- [ ] 2.2 Bind that endpoint to the compose / loopback network only. Do not publish it through public nginx.
- [ ] 2.3 Point Laravel setup preview and create/update validation at the same endpoint so preview and save cannot disagree. If the engine is unreachable, both fail with a clear error and persist nothing.
- [ ] 2.4 Delete `app/Strategies/*`, their `AppServiceProvider` bindings, and the PHP `onTick` path in `GridPlanEvaluator` once Laravel uses the engine client.
- [ ] 2.5 Keep Pest preview, preflight, and "preview and save agree" coverage green, with the engine in the test path (or a documented test double of the HTTP API, not a second grid calculator).
- [ ] 2.6 Update provisioning / strategy docs to state that Go is the only evaluator.

## 3. Live client foundation (fakes only)

- [ ] 3.1 Introduce a Go exchange-client interface equivalent to the PHP `ExchangeClient` (place limit, cancel, fetch order, open orders, trading rules, balance, normalized errors, rate-limit backoff).
- [ ] 3.2 Implement Binance against official REST (and testnet base URLs), decrypting credentials only in memory as already specified, with secrets redacted from logs.
- [ ] 3.3 Add a MAX adapter behind the same interface. Prove it only with fakes. Do not add or run a MAX live or MAX testnet suite in this change.
- [ ] 3.4 Honor existing proxy, custom base URL, or NAS relay settings as transport only. Do not redesign the relay.
- [ ] 3.5 Enforce `TRADING_LIVE_ENABLED` default false (empty parses as false). A closed gate pauses or refuses a live bot, records a risk event, and MUST NOT convert it to paper.
- [ ] 3.6 Honor `TRADING_EXCHANGE_SANDBOX` and credential `testnet` so testnet keys never hit production base URLs.
- [ ] 3.7 Add unit tests against a fake HTTP exchange for decrypt-in-memory, redaction, closed-gate behavior, sandbox URL selection, and normalized errors. No private calls and no real-money keys.

## 4. Place, cancel, idempotency, risk, and reconcile (fakes first)

- [ ] 4.1 Implement live place and cancel in the engine so a live bot records the exchange order identifier, tracks open orders, and surfaces rejections without leaving a phantom open order.
- [ ] 4.2 Assign a stable, per-intent client order ID (bot, level or strategy key, side, and a generation that changes only when the intent is replaced). Persist it with the intent and reuse it on retry.
- [ ] 4.3 Apply the existing risk gates immediately before every live place (exposure, daily loss, kill switch, open-order caps, and other already-ported checks). A blocked place is a risk event; an expected inventory deferral is not.
- [ ] 4.4 Reconcile local open orders with the exchange on engine start, before new ticks, and periodically using `TRADING_ORDER_SYNC_SECONDS` (default 5s) or a documented engine equivalent. Do not invent fills.
- [ ] 4.5 Keep Laravel `CancelBotOrders` / `CancelOrder` as the emergency path when kill-switch or stop runs and the heartbeat is missing or expired, or command publish fails. Add or update a test that this path still exists.
- [ ] 4.6 Cover place, reject, cancel, retry-with-same-client-order-id, startup reconcile, and periodic reconcile with fake-exchange Go tests. Still no real-money calls.

## 5. Opt-in Binance testnet proof

- [ ] 5.1 Add an opt-in Go test suite (equivalent in spirit to PHP `CCXT_TESTNET=1`) that places, cancels, and reconciles on **Binance testnet only**, skipped by default in CI.
- [ ] 5.2 Document how to run that suite with testnet keys, and that it MUST NOT be pointed at production keys or production URLs.
- [ ] 5.3 Do not add a MAX testnet, MAX live, or real-money soak task. MAX remains fake-only in this change. If Johnson later wants a MAX or real-money proof, it needs a new, explicit yes for that exact action.

## 6. Docs and UI

- [ ] 6.1 Update `docs/exchanges.md`, `docs/engine.md`, `docs/runtime.md`, `docs/risk.md`, `docs/security.md`, and the README: live execution is implemented in the Go engine, default gate is false, first rollout is testnet, production live requires Johnson's explicit yes, and emergency cancel remains.
- [ ] 6.2 Keep production and default compose at `TRADING_LIVE_ENABLED=false`.
- [ ] 6.3 Adjust dashboard / bot-mode copy so live is not described as unavailable, and so testnet versus real-money live is explicit. Do not auto-promote paper bots.

## 7. Minor cleanup

- [ ] 7.1 Fix the stale command-processor comment in `engine/main.go` (commands already route to runtime; it is not an in-process leftover bus).
- [ ] 7.2 Rename `heartbeat.NewStub` to a name that matches the real Redis heartbeat and remove the "until the tick loop lands" comment.
- [ ] 7.3 Verify `TickResult`, `ReconcileResult`, and `CapacityReport` have no remaining callers; if unused, delete them.
- [ ] 7.4 Remove engine-owned columns from `Bot` `$fillable` (`status`, `last_error`, inventory and PnL fields, `consecutive_failures`, `last_tick_at`, `last_successful_tick_at`). Keep Laravel-owned fields including `desired_status`. Confirm engine-owned `settings` keys stay protected by the existing merge rules.

## 8. Verify

- [ ] 8.1 Run `openspec validate harden-go-engine-runtime --strict` (or `openspec validate --specs --strict` after archive).
- [ ] 8.2 Run the Go test suite (including golden fixtures) and Laravel Pest + PHPStan for the affected paths.
- [ ] 8.3 Confirm the verify run made no real-money live-account calls, `TRADING_LIVE_ENABLED` remained false in default/production compose, and `CancelBotOrders` is still present.
