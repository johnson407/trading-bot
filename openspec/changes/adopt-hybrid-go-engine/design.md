# Design

## Context

See `proposal.md` for motivation. Today a single Laravel app does both management and execution. `routes/console.php` runs `bots:dispatch` every second; `DispatchBotTicks` due-checks `tick_interval_seconds` (default 60) with a Redis cache lock and queues `RunBotTick` on Horizon. `BotTickPipeline` fetches a REST ticker, evaluates daily loss and the exit guard, runs a PHP strategy, syncs the grid, places or simulates orders, and records fills. Paper fills use `PaperExchangeClient::qualifyingFills` (buy when last ≤ limit, sell when last ≥ limit) only at tick time. Live orders go through CCXT and `LiveModeGuard`. Backtests are `RunBacktest` jobs on a dedicated Horizon queue. `bots:health` runs every 30 seconds; `backtests:fail-stale` runs every five minutes. The dashboard polls every five seconds and does not use WebSockets. There is no Go code.

Johnson confirmed Option A, a hard cutover, Go as writer of the trading tables, Laravel as migration owner, and a Go backtest engine. `add-qnap-ci-deploy` is an active change (29/30 tasks) and is not edited here. After review, Johnson also confirmed: an expired heartbeat is a warning only; a user-triggered kill-switch or live stop uses a Laravel emergency cancel when the engine is unavailable; `desired_status` is split from runtime `status`.

## Goals / Non-Goals

**Goals:**

- Split the system into a Laravel management plane and a Go trading engine, with a clear table-ownership matrix and a versioned Redis command/event protocol.
- Preserve existing safety gates, tick/stale semantics, paper-fill behaviour, and the 5s browser poll.
- Keep the kill-switch immediate for live orders even when Redis or the engine is down, via a user-triggered Laravel emergency cancel.
- Land a local Docker `engine` service and a phased, testable apply plan. Capture PHP golden fixtures before deleting the PHP engine.

**Non-Goals:**

- Enabling live trading, calling live exchange endpoints, or changing `TRADING_LIVE_ENABLED` defaults.
- Editing `openspec/changes/add-qnap-ci-deploy/`, `docker-compose.prod.yml`, or the GHCR/CI pipeline in this change.
- Replacing the dashboard poll with a browser WebSocket.
- Improving paper fills to trigger intra-tick (that would be a later, explicit spec change).
- A second, PHP copy of engine risk or strategy logic after cutover.
- Automatically pausing, canceling, or activating the kill-switch when the engine heartbeat expires.

## Decisions

### 1. Why Go for the engine, Laravel for the management plane

The engine is a long-lived process: one Binance user-data/market WebSocket (or a small pool), per-bot goroutines or a single event loop, reconnect, and low-latency cancel paths (kill-switch). A single static binary is easier to supervise than PHP-FPM plus Horizon plus `schedule:work` for that job. Laravel already provides auth, Inertia/Vue, policies, encryption, and migrations; rewriting the dashboard would not improve trading. The cost is a two-language repo and a rewrite of the hottest path, which this design offsets with contracts and golden tests.

### 2. Service boundaries

**Laravel owns:**

- HTTP/Inertia UI, Breeze auth, policies, and form/request validation for bot create/edit, risk limits, exit-guard settings, credentials, and live confirmation phrases.
- Persistence of users, bot identity and configuration (`name`, `symbol`, `exchange`, `exchange_credential_id`, `mode`, `strategy_key`, `capital_allocation`, `tick_interval_seconds`, fee rate, exit-guard settings), `desired_status`, `risk_limits` configuration, and encrypted `exchange_credentials`.
- Publishing commands on the Redis command stream when the user starts, pauses, stops, cancels an order, activates the kill-switch, or submits a backtest or sweep. Laravel writes `desired_status` in the same action so the DB remains a fallback. Laravel does not write `status` or `last_error` after the ownership migration.
- The user-triggered emergency cancel path: when the user activates the kill-switch or stops a live bot, and the engine heartbeat is missing or expired or Redis `XADD` fails, Laravel cancels open live orders in that same action via `CancelBotOrders`. This path is not used when the heartbeat is fresh and `XADD` succeeds.
- Reading trading tables for the dashboard, activity, alerts, and analytics. Equity snapshots remain a Laravel scheduled job that only reads engine-written inventory and P&L.
- All schema migrations. Aside from the emergency cancel exception above, Laravel never runs exchange orders, ticks, or backtest replay after cutover.
- Optional public REST ticker for *UI previews* (create/edit ladder) so operators can open the form if the engine is down. This path never places orders and never uses private credentials.

**The Go engine owns:**

- Process lifetime, heartbeats, per-bot tick scheduling (same interval rules as today), strategy lifecycle, grid calculation, order placement and cancellation, paper fills, live CCXT/REST private calls when the live gate is on, fill processing, inventory, reconciliation, live balance sync, stale-tick pauses, stale-backtest failure, consecutive-failure tracking, and backtest/sweep execution.
- WebSocket market data with REST fallback.
- Decrypting credentials at runtime, enforcing all pre-trade risk and live gates, and writing trading tables, runtime `status`, `last_error`, and engine activity/risk events.

**Config validation:** Laravel validates on write for UX (existing Form Requests). The engine re-validates configuration and risk limits before start and before each placement, and refuses to trade on invalid data. Trade-time enforcement lives only in Go; Laravel does not keep a second order-placement guard after cutover.

### 3. Table-ownership matrix

Laravel owns every migration. "Writer" means runtime writes after apply. Dual writers of the same columns are forbidden, with the narrow emergency-cancel exceptions noted below.

| Table / columns | Writer | Readers | Notes |
| --- | --- | --- | --- |
| `users`, auth tables | Laravel | Laravel | Engine receives `user_id` on commands only. |
| `exchange_credentials` | Laravel | Engine (decrypt at need) | Secrets never logged or copied to disk. |
| `bots` identity/config (`name`, `symbol`, `exchange`, `exchange_credential_id`, `mode`, `strategy_key`, allocations, `tick_interval_seconds`, fee, exit-guard *settings*) | Laravel | Both | Written at create/edit. |
| `bots.desired_status` | Laravel | Both | Laravel writes this on start, pause, stop, and kill-switch (and `idle` on create). Added by a Laravel migration and backfilled from the current `status`. |
| `bots.status` (runtime) | Engine | Both | Engine applies user intent from `desired_status`. It MUST NOT set `status` to running when `desired_status` is paused or stopped. |
| `bots` runtime (`last_tick_at`, `last_successful_tick_at`, `last_error`, `base_inventory`, `quote_inventory`, `average_entry_price`, `realized_pnl`, `fees_paid`, `consecutive_failures`) | Engine | Laravel | Laravel never writes `last_error` after the ownership migration. |
| `bots.settings` keys `mark_price`, `recent_prices`, `daily_loss_baseline` | Engine | Both | Engine merges only these keys. |
| `bots.settings` other keys | Laravel | Both | Laravel may initialize `settings` to `{}` on create. Later Laravel updates MUST merge and MUST NOT overwrite engine-owned keys. |
| `strategy_configs.configuration`, `strategy_key` | Laravel | Both | Written at create/edit. |
| `strategy_configs.state`, `state_version` | Engine | Laravel | |
| `grids`, `grid_levels` | Engine | Laravel | |
| `orders`, `fills`, `inventory_ledger_entries` | Engine | Laravel | Exception: when the user-triggered live emergency cancel runs (engine heartbeat missing or expired, or `XADD` failed), Laravel may update already-open order rows to canceled or failed via `CancelBotOrders` / `CancelOrder`. Laravel never places new orders. |
| `risk_limits` configuration columns | Laravel | Both | Engine enforces, and does not edit configured limits. |
| `risk_events` from engine actions | Engine | Laravel | Daily loss, stale, exit guard, live-gate, consecutive failures, and other runtime risk types. |
| `risk_events` emergency-cancel failures | Laravel (insert-only) | Laravel | Disjoint types only: `risk.kill_switch_cancel_failed` and `risk.stop_cancel_failed`. The engine does not write these types. |
| `activity_logs` | Both (insert-only, disjoint types) | Laravel | Locked lists below. No updates or deletes of the other side's rows. |
| `alerts` | Laravel | Laravel | Triggered from engine events and/or DB activity; email stays on Horizon. |
| `equity_snapshots` | Laravel | Laravel | Read-only over bot inventory/P&L. |
| `market_candles` | Engine | Engine, Laravel (optional read) | Public candle cache for backtests. |
| `backtest_runs`, `backtest_sweeps` request rows | Laravel inserts `queued` | Both | Laravel creates the request. |
| `backtest_runs` / `backtest_sweeps` status, metrics, errors; `backtest_fills` | Engine | Laravel | Includes failing a stale run. Laravel does not run `backtests:fail-stale` after cutover. |

If a later migration is required (for example `desired_status` or a command-receipt table), Laravel authors and runs it; the engine never migrates.

**`activity_logs` type lists (insert-only, disjoint):**

Laravel may insert only:

- `bot.created`, `bot.updated`, `bot.exit_guard_updated`
- `bot.started`, `bot.paused`, `bot.stopped` (user-control acknowledgements only)
- `kill_switch.activated`

The engine may insert only:

- `bot.stale`, `bot.reconciled`, `bot.reconciliation_conflict`
- `bot.balance_synced`, `bot.balance_sync_skipped`
- `bot.strategy_stop`, `bot.strategy_state_invalid`
- `bot.daily_loss_paused`, `bot.exit_guard_triggered`, `bot.live_trading_disabled`
- `exchange.failure_threshold`
- `order.canceled`, `order.cancel_failed`, `order.filled`
- `order.level_missing`, `order.min_amount`, `order.min_notional`, `order.placement_uncertain`, `order.rejected`
- `orders.synced`

The engine MUST NOT insert Laravel-listed types. A runtime pause that today uses `bot.paused` (for example in fill processing) MUST use a listed engine type instead, not `bot.paused`. New types require a spec/design update before use.

### 4. Communication protocol

Postgres is the system of record for bot configuration, desired and runtime status, orders, fills, and backtest results.

Redis Streams carry commands and events. Use consumer groups so the engine can scale to one active consumer for commands (a single engine instance in v1; a second instance must not take over without a lock — v1 is single-instance, documented). Delivery is at-least-once.

**Streams (v1 names):**

- `engine.commands` — Laravel is the writer; the engine is the consumer group `engine`.
- `engine.events` — the engine is the writer; Laravel (and later alert workers) consume via group `laravel`.
- `engine.commands.dlq` — dead-letter stream for poison commands. `engine.events.dlq` for poison events.

**Envelope (JSON, field `v` is the schema version):**

```json
{
  "v": 1,
  "id": "01jcexxxxxxxxxxxxxxxxxxxxx",
  "type": "bot.start",
  "ts": "2026-11-23T05:00:00Z",
  "user_id": 1,
  "bot_id": 208,
  "payload": {}
}
```

- `id` is a unique idempotency key (ULID or UUID). The producer sets it; the engine stores `engine:cmd:{id}` in Redis (TTL ≥ 7 days) and ignores a retry with the same `id` after success or a definitive rejection.
- `v` is an integer. v1 consumers process `v = 1`. A higher `v` is not executed; it is logged (no secrets) and recorded as a failed command event so operators can upgrade. Unknown fields in a v1 payload are ignored.
- `ts` is UTC RFC 3339.

**At-least-once, ACK, reclaim, and poison:**

1. The engine reads with a consumer group. After durable Postgres effects (and a definitive success or rejection), it writes `engine:cmd:{id}` and then `XACK`s the entry.
2. On engine start, it auto-claims idle pending entries (PEL) older than a documented idle threshold (30 seconds) and reprocesses them. If `engine:cmd:{id}` already marks the command completed, it `XACK`s with no second effect. If not, it re-applies using the command's idempotency and per-action idempotency (for example client order ids).
3. After five failed processing attempts, the engine `XADD`s the message to `engine.commands.dlq`, emits a failed command event without secrets, and `XACK`s the original so the group is not blocked.
4. Commands for the same `bot_id` are applied serially in stream order. Different bots may proceed concurrently. A `kill_switch` for a user is serialized against that user's bot commands.
5. Laravel event consumers follow the same `XACK`, PEL auto-claim, and dead-letter rules on `engine.events` / `engine.events.dlq`.

**Command types (v1):**

| `type` | Payload (required) | Effect |
| --- | --- | --- |
| `bot.start` | `bot_id` | If the bot is startable, engine sets running and begins ticks (live start also enforces the live gate and balance). |
| `bot.pause` | `bot_id` | Stop new placements; leave open orders and state. |
| `bot.stop` | `bot_id` | Cancel open orders; mark stopped. |
| `order.cancel` | `bot_id`, `order_id` | Cancel one order. |
| `kill_switch` | `user_id` | Pause every bot for that user and cancel their open orders immediately. |
| `backtest.run` | `backtest_run_id` | Execute the queued run. |
| `backtest.sweep` | `backtest_sweep_id` | Execute the queued sweep. |

**Event types (v1, non-exhaustive):** `engine.heartbeat`, `bot.started`, `bot.paused`, `bot.stopped`, `bot.tick_completed`, `bot.error`, `kill_switch.completed`, `backtest.progress`, `backtest.completed`, `backtest.failed`. Events reference the same `id` when they complete a command (`correlation_id` in the payload equals the command `id`). Payloads MUST NOT include credentials, `APP_KEY`, or raw authorization headers.

**Kill-switch immediacy:** Laravel, in one user action: (1) writes `desired_status` paused for the user's bots, (2) attempts `XADD` of `kill_switch` to `engine.commands`. If the heartbeat is fresh and `XADD` succeeds, the engine processes `kill_switch` as soon as it is read and cancels open orders without waiting for the next tick. Independently, the engine refreshes `desired_status` from Postgres at least every second and whenever it would place or cancel, so a paused or stopped desired row is honored even if the Stream is down. **Emergency path (user-triggered only):** if Redis `XADD` fails, or the engine heartbeat is already missing or expired, Laravel SHALL, in that same action, cancel open orders on the user's *live* bots via `CancelBotOrders`. This path is not used when the heartbeat is fresh and `XADD` succeeds. Laravel still does not write `status` or `last_error`. `CancelBotOrders` is retained after the PHP engine is deleted. For paper bots, writing `desired_status` is enough while the engine is down (no ticks or fills). Stop of a live bot uses the same emergency cancel when the heartbeat is missing or expired or `XADD` fails.

**Heartbeat:** the engine SET `engine:heartbeat` (JSON: `ts`, `revision`, `running_bots`) with TTL 10 seconds, refreshed at least every 5 seconds, and also emits `engine.heartbeat` events. Laravel health and the dashboard treat a missing or expired key as "engine unavailable" and show a *warning only*. A missing or expired heartbeat MUST NOT automatically pause bots, cancel orders, or activate the kill-switch. Cancels of live orders while the engine is down happen only when the user activates the kill-switch or stops a live bot.

**Tick and stale semantics (unchanged):** each running bot is evaluated at `tick_interval_seconds` (minimum 1, default 60). A bot has at most one evaluation in progress. Paper fills, daily-loss, and exit-guard checks run when a tick runs. Stale threshold remains `max(trading.tick.stale_after_seconds, tick_interval_seconds + trading.health.check_interval_seconds)` with default stale 180 seconds. The engine, not `bots:health` in Laravel, pauses stale bots and writes `bot.stale` / `risk.stale_bot`. Laravel may still *display* staleness from `last_successful_tick_at`.

**Browser:** list, detail, and dashboard pages keep the 5-second Inertia poll. No dashboard WebSocket in this change.

### 5. Market data and paper fills

The engine subscribes to Binance market WebSocket (book ticker or trade/ticker as needed to supply last/bid/ask) for symbols of running bots. It MUST:

- reconnect with backoff on disconnect;
- detect sequence/gap or unexpected quiet periods and recover by a REST `fetch_ticker` (or equivalent) snapshot, then resubscribe;
- fall back to periodic REST tickers when the socket cannot be established, and record that degradation without secrets;
- never require private credentials for public market data.

Paper fills keep current semantics: at each tick, using the current market last, a buy limit fills at its limit when last ≤ price, a sell when last ≥ price, closest-to-market first, same inventory and fee path as today (`PaperExchangeClient::qualifyingFills`). A price that crosses and recrosses between ticks is not guaranteed to fill. Improving to intra-tick WS prints is out of scope.

UI preview tickers may continue to use Laravel's public REST cache and MUST NOT be a substitute for the engine's trading feed.

### 6. Docker (local, this change)

Add an `engine` service to `docker-compose.yml` only:

- Multi-stage Docker build (Go toolchain build stage, runtime `gcr.io/distroless/static` or a minimal `alpine` image).
- Run as a non-root user. No bind-mounted source required in production-like runs; local dev may mount for iteration.
- Healthcheck: process HTTP `GET /healthz` (liveness: process up; readiness: Postgres and Redis reachable, heartbeat recently written) or an equivalent that Compose can call. Do not use a check that calls the exchange.
- Environment: `APP_KEY`, `DB_*`, `REDIS_*`, `TRADING_LIVE_ENABLED` (default false), other non-secret `TRADING_*` / risk defaults. Same values as `app` / `horizon`. Never bake secrets into the image.
- `depends_on` healthy Postgres and Redis. The engine reconciles running bots on start (replacing `php artisan bots:reconcile` on the scheduler).
- Scheduler command drops `bots:reconcile`, `bots:dispatch`, `bots:sync-orders`, `bots:sync-balances`, `bots:health`, and `backtests:fail-stale`. Remaining Laravel schedule: `alerts:prune`, `analytics:capture-equity`, and `analytics:prune-equity`. Horizon keeps the default queue for `SendAlertEmail` (and similar); the `backtests` queue and tick/order jobs go away at cutover.

**QNAP / CI overlap (do not implement here):** `docker-compose.prod.yml` and the GHCR workflow currently publish and run six services (app, nginx, postgres, redis, horizon, scheduler). After `add-qnap-ci-deploy` is archived, a follow-up must build and publish an engine image, add the service and healthcheck to prod Compose, update the QNAP pull/rollback script to wait for seven services, pass `APP_KEY` and trading flags into the engine, and run `go test` in CI. Until that follow-up lands, a SHA that has completed phase 8 (PHP engine deletion) MUST NOT be deployed to QNAP from `origin/main`. Land phase 8 on a branch or tag (for example `hybrid-engine`) or treat `main` after phase 8 as not NAS-deployable until the follow-up ships. This change does not edit `openspec/changes/add-qnap-ci-deploy/`.

### 7. Safety and credentials

- `TRADING_LIVE_ENABLED` defaults to false and is enforced *inside the engine* on start and before every live private call. A closed gate pauses a live bot and MUST NOT silently run it as paper.
- Go ports, as blocking pre-trade / pre-start checks, with tests: `LiveModeGuard` (gate, and on start: confirmation already required by Laravel plus sufficient free quote vs capital), `RiskGuard` (position and open-order caps, inventory reservation), `DailyLossMonitor` (per-bot and account-wide; pause and cancel), protective exit guard (pause and cancel, no market flatten, no auto-resume), `ConsecutiveFailureTracker`, and the `TRADING_EXCHANGES` allowlist. After cutover there is no PHP implementation of these runtime checks.
- Laravel still requires the `<bot name> on <exchange>` phrase at live create/start before it publishes `bot.start`. The engine still refuses live trading when the env gate is off or balance is insufficient.
- Credentials: Laravel continues to encrypt `exchange_credentials` at rest with the Eloquent `encrypted` cast. That cast calls `encrypt($value, false)` / `decrypt($value, false)`, which is the same as `Crypt::encryptString` / `Crypt::decryptString`, and is **not** `Crypt::encrypt()` (whose default PHP-serializes the plaintext). The payload is AES-256-CBC (`config/app.php` `cipher`). `APP_KEY` may start with `base64:`; strip that prefix and base64-decode the remainder to the raw 32-byte key. The stored string is base64 of a JSON object with `iv`, `value`, `mac`, and `tag`. `iv` is base64 of the raw IV; `value` is the OpenSSL ciphertext (base64, `openssl_encrypt` option `0`); `mac` is HMAC-SHA256 over the concatenation of the JSON `iv` and `value` strings; `tag` is present and empty for this non-AEAD cipher. The engine decrypts only in memory when a live bot needs a private client, using the same `APP_KEY` and the same `previous_keys` list Laravel loads from `config/app.php`. It MUST NOT log `APP_KEY`, previous keys, raw payloads, API keys, secrets, or signatures; must not write them to events, activity rows, or crash dumps; and must not persist a decrypted copy. Key material is not baked into the image. Unit tests MUST use a stub ciphertext generated with `Crypt::encryptString` (or the Eloquent `encrypted` cast), never `Crypt::encrypt()`, and never a value from `.env`.
- Apply, local dev, and default CI use paper only. The existing opt-in testnet suite remains skipped unless explicitly enabled. Apply MUST NOT place live orders or call authenticated live endpoints.

### 8. Apply phases (verifiable units)

Each phase ends with the proofs listed in `tasks.md` (typically the `engine` `make test` target, the documented Pest suite, PHPStan, and `openspec validate --strict`). No phase enables live trading. Go changes cannot be closed without a green `make test` in `engine/`.

1. **Skeleton:** Go module under `engine/`, a required local `make test` target, Compose `engine` service with `/healthz`, no trading behaviour.
2. **Contract:** documented Stream schemas (this design), including ACK / PEL / dead-letter / per-bot ordering tests; `desired_status` migration; optional receipt migration if Redis-only receipts are not enough.
3. **Market data:** WebSocket client, reconnect, gap/REST fallback, tests with a fake socket; no orders.
4. **Fixtures then paper engine:** *before deleting PHP*, capture golden fixtures from the current pipeline and strategies. Port the tick loop, grid, paper fills, and each of the four strategies as separate units, each proven against fixtures.
5. **Risk:** port each guard (live mode, RiskGuard, daily loss, exit guard, consecutive failures, allowlist) and credential decryption as separate units with tests.
6. **Backtest port:** move replay, sweeps, and stale-run failure to the engine as separate units; Laravel only enqueues and reads; golden tests against PHP results.
7. **Laravel wiring:** publish commands one path at a time (start, pause, stop, cancel, kill-switch, backtest), consume events, dashboard heartbeat warning only, trim Horizon/scheduler including `bots:health` and `backtests:fail-stale`. Keep PHP engine until this phase is green, then
8. **Cutover then remove PHP engine:** follow the cutover plan below. Delete the tick pipeline, PHP strategies, order *placement* and sync/reconcile/dispatch jobs, `RunBotTick` / `PlaceOrder` / `SyncOrders` / `RunBacktest` and `app/Domain/Backtest/Engine`. **Keep** `CancelBotOrders` and the `CancelOrder` path it uses. Docs update, including the operator runbook. Do not merge this phase to the QNAP-deployable `origin/main` until the engine follow-up lands.
9. **Docs and operator notes:** runtime, exchanges, security, README; note the deferred prod/CI work.

### 9. Cutover plan

The PHP tick/order/backtest path and the Go engine MUST NOT run against the same bots at the same time. Engine start recovery (reconcile running bots) is necessary but not sufficient for the first cutover.

1. Enter a maintenance window. Pause all running bots, or wait until no tick or `PlaceOrder` / `RunBacktest` job is in flight.
2. Drain Horizon queues used for ticks, orders, and backtests until empty.
3. Stop scheduling `bots:dispatch`, `bots:health`, `bots:sync-orders`, `bots:sync-balances`, `bots:reconcile`, and `backtests:fail-stale`, and confirm those workers are stopped.
4. Start the Go engine. It must reconcile `desired_status = running` bots and adopt open orders *before* it evaluates new ticks or places new orders.
5. Confirm a fresh heartbeat and that paper evaluation and command paths work.
6. Only then delete the PHP engine (phase 8), keeping `CancelBotOrders`.
7. Record the steps in `docs/runtime.md` (or a linked cutover runbook) for operators.

## Risks

- Hard cutover leaves no PHP fallback; a production deploy of this change must not ship until paper parity and command paths are proven. Phase 8 SHAs must not go to QNAP `origin/main` until the engine follow-up lands, or NAS production would have no trading engine.
- `desired_status` vs `status` races — the engine must treat `desired_status` as authoritative and must not set running over a user pause, stop, or kill.
- Laravel encryption format must be reproduced exactly (`encryptString`, not serialized `encrypt`) or live starts will fail. Cover with a fixture generated by `Crypt::encryptString` (never a real key from `.env`).
- Paper/WebSocket last-price sampling can still miss intra-tick crosses — accepted, documented, same as today.
- Single engine instance in v1 is a single point of failure; the dashboard warns on a missing heartbeat, and the user-triggered emergency cancel covers live orders if the operator uses kill-switch or stop. There is no automatic pause when the engine dies.
- Backtest determinism must be proven; floating decimal differences between PHP `Decimal` and Go decimal libraries are a known risk (use the same scale/rounding rules).

## Open questions

1. **Resolved:** On expired engine heartbeat, Laravel only displays a warning. It does not automatically pause bots, cancel orders, or activate the kill-switch. Live-order cancels while the engine is down remain user-triggered (kill-switch or stop) via the Laravel emergency path.
2. Should UI market previews stay on Laravel public REST, as designed here?
3. Is `engine/` the right module path?
4. v1 is a single engine instance — acceptable for laptop and first NAS follow-up?
5. Keep paper-fill-at-tick parity (this design), or specify intra-tick fills in a later change?
