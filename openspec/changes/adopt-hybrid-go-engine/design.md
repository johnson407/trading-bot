# Design

## Context

See `proposal.md` for motivation. Today a single Laravel app does both management and execution. `routes/console.php` runs `bots:dispatch` every second; `DispatchBotTicks` due-checks `tick_interval_seconds` (default 60) with a Redis cache lock and queues `RunBotTick` on Horizon. `BotTickPipeline` fetches a REST ticker, evaluates daily loss and the exit guard, runs a PHP strategy, syncs the grid, places or simulates orders, and records fills. Paper fills use `PaperExchangeClient::qualifyingFills` (buy when last ≤ limit, sell when last ≥ limit) only at tick time. Live orders go through CCXT and `LiveModeGuard`. Backtests are `RunBacktest` jobs on a dedicated Horizon queue. The dashboard polls every five seconds and does not use WebSockets. There is no Go code.

Johnson confirmed Option A, a hard cutover, Go as writer of the trading tables, Laravel as migration owner, and a Go backtest engine. `add-qnap-ci-deploy` is an active change (29/30 tasks) and is not edited here.

## Goals / Non-Goals

**Goals:**

- Split the system into a Laravel management plane and a Go trading engine, with a clear table-ownership matrix and a versioned Redis command/event protocol.
- Preserve existing safety gates, tick/stale semantics, paper-fill behaviour, and the 5s browser poll.
- Land a local Docker `engine` service and a phased, testable apply plan. Capture PHP golden fixtures before deleting the PHP engine.

**Non-Goals:**

- Enabling live trading, calling live exchange endpoints, or changing `TRADING_LIVE_ENABLED` defaults.
- Editing `openspec/changes/add-qnap-ci-deploy/`, `docker-compose.prod.yml`, or the GHCR/CI pipeline in this change.
- Replacing the dashboard poll with a browser WebSocket.
- Improving paper fills to trigger intra-tick (that would be a later, explicit spec change).
- A second, PHP copy of engine risk or strategy logic after cutover.

## Decisions

### 1. Why Go for the engine, Laravel for the management plane

The engine is a long-lived process: one Binance user-data/market WebSocket (or a small pool), per-bot goroutines or a single event loop, reconnect, and low-latency cancel paths (kill-switch). A single static binary is easier to supervise than PHP-FPM plus Horizon plus `schedule:work` for that job. Laravel already provides auth, Inertia/Vue, policies, encryption, and migrations; rewriting the dashboard would not improve trading. The cost is a two-language repo and a rewrite of the hottest path, which this design offsets with contracts and golden tests.

### 2. Service boundaries

**Laravel owns:**

- HTTP/Inertia UI, Breeze auth, policies, and form/request validation for bot create/edit, risk limits, exit-guard settings, credentials, and live confirmation phrases.
- Persistence of users, bot identity and configuration (`name`, `symbol`, `exchange`, `mode`, `strategy_key`, `capital_allocation`, `tick_interval_seconds`, fee rate, exit-guard settings), `risk_limits`, and encrypted `exchange_credentials`.
- Publishing commands on the Redis command stream when the user starts, pauses, stops, cancels an order, activates the kill-switch, or submits a backtest or sweep. Laravel also writes the corresponding *desired* bot/run status in Postgres in the same action (paused/stopped/running/queued) so the DB remains a fallback.
- Reading trading tables for the dashboard, activity, alerts, and analytics. Equity snapshots remain a Laravel scheduled job that only reads engine-written inventory and P&L.
- All schema migrations. Laravel never runs exchange orders, ticks, or backtest replay after cutover.
- Optional public REST ticker for *UI previews* (create/edit ladder) so operators can open the form if the engine is down. This path never places orders and never uses private credentials.

**The Go engine owns:**

- Process lifetime, heartbeats, per-bot tick scheduling (same interval rules as today), strategy lifecycle, grid calculation, order placement and cancellation, paper fills, live CCXT/REST private calls when the live gate is on, fill processing, inventory, reconciliation, live balance sync, stale-tick pauses, consecutive-failure tracking, and backtest/sweep execution.
- WebSocket market data with REST fallback.
- Decrypting credentials at runtime, enforcing all pre-trade risk and live gates, and writing trading tables and engine activity/risk events.

**Config validation:** Laravel validates on write for UX (existing Form Requests). The engine re-validates configuration and risk limits before start and before each placement, and refuses to trade on invalid data. Trade-time enforcement lives only in Go; Laravel does not keep a second order-placement guard after cutover.

### 3. Table-ownership matrix

Laravel owns every migration. "Writer" means runtime writes after apply. Dual writers of the same columns are forbidden.

| Table / columns | Writer | Readers | Notes |
| --- | --- | --- | --- |
| `users`, auth tables | Laravel | Laravel | Engine receives `user_id` on commands only. |
| `exchange_credentials` | Laravel | Engine (decrypt at need) | Secrets never logged or copied to disk. |
| `bots` identity/config (`name`, `symbol`, `exchange`, `mode`, `strategy_key`, allocations, `tick_interval_seconds`, fee, exit-guard *settings*, user-facing desired `status` on start/pause/stop/kill) | Laravel | Both | Laravel updates desired status when the user acts. |
| `bots` runtime (`last_tick_at`, `last_successful_tick_at`, `last_error`, `base_inventory`, `quote_inventory`, `average_entry_price`, `realized_pnl`, `fees_paid`, `consecutive_failures`, engine `settings` such as mark, recent prices, daily-loss baselines) | Engine | Laravel | |
| `bots.status` when the *engine* pauses or stops a bot (stale, risk, live gate closed, strategy stop, reconcile conflict) | Engine | Both | Engine must not overwrite a newer user kill/pause/stop with `running`. |
| `strategy_configs.configuration`, `strategy_key` | Laravel | Both | Written at create/edit. |
| `strategy_configs.state`, `state_version` | Engine | Laravel | |
| `grids`, `grid_levels` | Engine | Laravel | |
| `orders`, `fills`, `inventory_ledger_entries` | Engine | Laravel | |
| `risk_limits` | Laravel | Both | Engine enforces, does not edit limits. |
| `risk_events` from engine actions | Engine | Laravel | Daily loss, stale, exit guard, live-gate, consecutive failures. |
| `activity_logs` | Both (insert-only, disjoint types) | Laravel | Laravel: `bot.created`, credential and control acknowledgements. Engine: tick, order, fill, cancel, reconcile, engine pauses. No updates or deletes of the other side's rows. |
| `alerts` | Laravel | Laravel | Triggered from engine events and/or DB activity; email stays on Horizon. |
| `equity_snapshots` | Laravel | Laravel | Read-only over bot inventory/P&L. |
| `market_candles` | Engine | Engine, Laravel (optional read) | Public candle cache for backtests. |
| `backtest_runs`, `backtest_sweeps` request rows | Laravel inserts `queued` | Both | Laravel creates the request. |
| `backtest_runs` / `backtest_sweeps` status, metrics, errors; `backtest_fills` | Engine | Laravel | |

If a later migration is required (for example a command-receipt table), Laravel authors and runs it; the engine never migrates.

### 4. Communication protocol

Postgres is the system of record for bot configuration, desired and actual status, orders, fills, and backtest results.

Redis Streams carry commands and events. Use consumer groups so the engine can scale to one active consumer for commands (a single engine instance in v1; a second instance must not take over without a lock — v1 is single-instance, documented).

**Streams (v1 names):**

- `engine.commands` — Laravel is the writer; the engine is the consumer group `engine`.
- `engine.events` — the engine is the writer; Laravel (and later alert workers) consume via group `laravel`.

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

**Kill-switch immediacy:** Laravel, in one user action: (1) marks the user's bots paused in Postgres, (2) XADD `kill_switch` to `engine.commands`. The engine processes `kill_switch` as soon as it is read and cancels open orders without waiting for the next tick. Independently, the engine refreshes bot status from Postgres at least every second and whenever it would place or cancel, so a paused/stopped row is honored even if the Stream is down. The Redis path is the immediate path; the DB path is the fallback and may be delayed by up to that one-second status refresh plus cancel latency.

**Heartbeat:** the engine SET `engine:heartbeat` (JSON: `ts`, `revision`, `running_bots`) with TTL 10 seconds, refreshed at least every 5 seconds, and also emits `engine.heartbeat` events. Laravel health and the dashboard treat a missing or expired key as "engine unavailable" and surface it. If the heartbeat is expired for longer than 30 seconds while bots are marked running, Laravel SHALL publish a protective `kill_switch` (or per-bot `bot.pause` if the switch is too coarse — default is pause all running bots for safety) only when Redis is available; if Redis is also down, the dashboard shows the outage and the operator uses operational recovery. This is conservative and should be confirmed (see open questions).

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
- Scheduler command drops `bots:reconcile`, `bots:dispatch`, `bots:sync-orders`, and `bots:sync-balances`. Remaining Laravel schedule: `alerts:prune`, `analytics:capture-equity`, `analytics:prune-equity`, and a small "engine heartbeat visible" health if useful. Horizon keeps the default queue for `SendAlertEmail` (and similar); the `backtests` queue and tick/order jobs go away at cutover.

**QNAP / CI overlap (do not implement here):** `docker-compose.prod.yml` and the GHCR workflow currently publish and run six services (app, nginx, postgres, redis, horizon, scheduler). After `add-qnap-ci-deploy` is archived, a follow-up must build and publish an engine image, add the service and healthcheck to prod Compose, update the QNAP pull/rollback script to wait for seven services, and pass `APP_KEY` and trading flags into the engine. Until then, NAS production stays on the PHP engine.

### 7. Safety and credentials

- `TRADING_LIVE_ENABLED` defaults to false and is enforced *inside the engine* on start and before every live private call. A closed gate pauses a live bot and MUST NOT silently run it as paper.
- Go ports, as blocking pre-trade / pre-start checks, with tests: `LiveModeGuard` (gate, and on start: confirmation already required by Laravel plus sufficient free quote vs capital), `RiskGuard` (position and open-order caps, inventory reservation), `DailyLossMonitor` (per-bot and account-wide; pause and cancel), protective exit guard (pause and cancel, no market flatten, no auto-resume), `ConsecutiveFailureTracker`, and the `TRADING_EXCHANGES` allowlist. After cutover there is no PHP implementation of these runtime checks.
- Laravel still requires the `<bot name> on <exchange>` phrase at live create/start before it publishes `bot.start`. The engine still refuses live trading when the env gate is off or balance is insufficient.
- Credentials: Laravel continues to encrypt at rest with the app `APP_KEY` (Illuminate default AES-256-CBC payload: JSON `iv`, `value`, `mac`, base64). The engine decrypts only in memory when a live bot needs a private client, using the same `APP_KEY` from the environment. It MUST NOT log `APP_KEY`, raw payloads, API keys, secrets, or signatures; must not write them to events, activity rows, or crash dumps; and must not persist a decrypted copy. Key material is not baked into the image.
- Apply, local dev, and default CI use paper only. The existing opt-in testnet suite remains skipped unless explicitly enabled. Apply MUST NOT place live orders or call authenticated live endpoints.

### 8. Apply phases (verifiable units)

Each phase ends with the proofs listed in `tasks.md` (typically `go test`, the documented Pest suite, PHPStan, and `openspec validate --strict`). No phase enables live trading.

1. **Skeleton:** Go module under `engine/`, unit-test CI hook, Compose `engine` service with `/healthz`, no trading behaviour.
2. **Contract:** documented Stream schemas (this design), optional receipt migration if Redis-only receipts are not enough, contract tests for envelope versioning and idempotency.
3. **Market data:** WebSocket client, reconnect, gap/REST fallback, tests with a fake socket; no orders.
4. **Fixtures then paper engine:** *before deleting PHP*, capture golden fixtures from the current pipeline and strategies. Port strategies, grid, paper fills, tick loop. Prove parity with those fixtures.
5. **Risk:** port the guards listed above with Pest/Go tests for pause, cancel, and no live fallback.
6. **Backtest port:** move replay and sweeps to the engine; Laravel only enqueues and reads; golden tests against PHP results.
7. **Laravel wiring:** publish commands, consume events, dashboard heartbeat, trim Horizon/scheduler. Keep PHP engine until this phase is green, then
8. **Remove PHP engine:** delete tick pipeline, PHP strategies, order services, sync/reconcile/dispatch jobs and schedule entries, `RunBacktest` and `app/Domain/Backtest/Engine`. Docs update.
9. **Docs and operator notes:** runtime, exchanges, security, README; note the deferred prod/CI work.

## Risks

- Hard cutover leaves no PHP fallback; a production deploy of this change must not ship until paper parity and command paths are proven, and QNAP/CI still need a follow-up or they will keep running PHP.
- Dual-write bugs on `bots.status` (user kill vs engine resume) — the engine must treat user-requested pause/stop/kill as authoritative.
- Laravel encryption format must be reproduced exactly in Go or live starts will fail (or worse, be retried). Cover with a fixture of a Laravel-encrypted stub secret in unit tests (never a real key from `.env`).
- Paper/WebSocket last-price sampling can still miss intra-tick crosses — accepted, documented, same as today.
- Single engine instance in v1 is a single point of failure; heartbeat plus protective pause is the mitigation until a leader-election design exists.
- Backtest determinism must be proven; floating decimal differences between PHP `Decimal` and Go decimal libraries are a known risk (use the same scale/rounding rules).

## Open questions

Recorded for Johnson in the proposal report; none block writing this proposal, but several should be confirmed before apply:

1. On expired engine heartbeat, is an automatic protective pause of all running bots acceptable, or should Laravel only display the outage?
2. Should UI market previews stay on Laravel public REST, as designed here?
3. Is `engine/` the right module path?
4. v1 is a single engine instance — acceptable for laptop and first NAS follow-up?
5. Keep paper-fill-at-tick parity (this design), or specify intra-tick fills in a later change?
