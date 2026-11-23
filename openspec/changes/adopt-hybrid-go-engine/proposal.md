# Proposal

## Why

The trading engine today runs inside Laravel: a one-second `bots:dispatch` due-check, Horizon jobs (`RunBotTick`, `PlaceOrder`, `SyncOrders`), REST `fetch_ticker` market data, PHP strategies, and a PHP backtest engine. That shape is a poor fit for a long-lived Binance WebSocket, tight per-bot concurrency, and a single long-running process that must stay up independently of HTTP workers. Johnson has confirmed Option A: keep Laravel + Inertia + Vue for the UI, strategy configuration, monitoring, and admin; move the core engine — WebSocket market data, grid calculation, order logic, the tick loop, and backtesting — into a Go service, with a hard cutover (the PHP engine is removed, no fallback flag).

The trade-off is real: two languages, two deployable units, and a rewrite of the hottest path. The mitigation is a contract-first design (Postgres as the system of record, versioned Redis Streams, a table-ownership matrix), golden tests captured from the current PHP behaviour before that code is deleted, and a phased apply with proof at each step. Live trading stays gated and is never exercised during apply.

## What Changes

- Introduce a Go `engine` service that owns runtime evaluation, market data, grid and order logic, paper and (gated) live execution, risk enforcement at trade time, reconciliation, live balance sync, health/stale pauses, stale-backtest failure, and backtest replay.
- Laravel remains the management plane: authentication, bot CRUD, configuration validation, credential storage, dashboard, alerts, analytics reads/snapshots, and all migrations/schema. Laravel reads trading tables; it does not write orders, fills, grids, inventory, or engine strategy state. `bots.desired_status` is Laravel-owned; `bots.status` and `bots.last_error` are engine-owned.
- Replace PHP tick dispatch and Horizon tick/order/backtest jobs with Redis Streams commands (start, pause, stop, cancel, kill-switch, backtest-request) and engine events/heartbeats. Consumption is at-least-once, with `XACK` after durable effects, pending-entry reclaim, dead-lettering, and per-bot serial ordering. The kill-switch is immediate via Redis when the engine is up; Postgres `desired_status` is the fallback if Redis is down but the engine is up. If the user activates the kill-switch or stops a live bot and the engine heartbeat is missing or expired, or Redis `XADD` fails, Laravel cancels open live orders in that same action via `CancelBotOrders` (kept after PHP engine deletion). An expired heartbeat is a dashboard warning only: no automatic pause, cancel, or kill-switch.
- Binance WebSocket for trading market data, with reconnect, gap detection, and REST fallback. Paper fills keep the current `PaperExchangeClient::qualifyingFills` semantics (evaluated from the market last at tick time).
- Local `docker-compose.yml` gains an `engine` service. Horizon and the Laravel scheduler are trimmed to leftover management work (alerts, analytics). `bots:health` and `backtests:fail-stale` move to the engine. Production Compose and the CI image build are deferred and noted so they can land after `add-qnap-ci-deploy`. Phase 8 (PHP engine deletion) is not deployable to the QNAP `main` path until that follow-up lands.
- Port `TRADING_LIVE_ENABLED`, `LiveModeGuard`, `RiskGuard`, `DailyLossMonitor`, the exit guard, `ConsecutiveFailureTracker`, and the exchange allowlist into Go as the single blocking pre-trade implementation. No live-to-paper fallback. Go decrypts Eloquent-`encrypted` credentials (`Crypt::encryptString` format: AES-256-CBC, HMAC-SHA256, not PHP-serialized) with the shared `APP_KEY` (and `previous_keys`) and never logs secrets.
- Remove the PHP tick pipeline, strategies, order services, sync/reconcile jobs, related scheduler entries, and the PHP backtest engine after golden fixtures are captured and the Go port is proven. `CancelBotOrders` stays for the emergency path. The PHP engine workers are already stopped; phase 8 is a direct swap that deletes the PHP engine. There is no drain-Horizon or adopt-open-orders-from-PHP transition, and no operator runbook for running both engines.

## Capabilities

### New Capabilities

None. Runtime, connectivity, grid, strategy, risk, backtesting, and dashboard behaviour are specified as deltas on the existing capabilities.

### Modified Capabilities

- `bot-runtime`: the Go engine, not Laravel Horizon, evaluates ticks, reconciles, and maintains heartbeats; command/event Streams, status-column ownership, and existing tick/stale semantics are required.
- `exchange-connectivity`: WebSocket market data with REST fallback; credential use by the engine; paper-fill parity retained.
- `grid-trading`: start/pause/stop are Laravel commands executed by the engine; live stop uses the emergency cancel path when the engine is unavailable.
- `strategy-engine`: lifecycle and state persistence run in the engine; Laravel stores configuration only.
- `risk-controls`: engine-side enforcement; kill-switch immediacy via Redis, DB fallback, and a user-triggered Laravel emergency cancel for live bots.
- `backtesting`: replay and sweeps run in the engine, including stale-run failure; the Laravel UI submits requests and reads results.
- `trading-dashboard`: five-second polling is unchanged; engine health is a warning only.

## Impact

- New tree for the Go module and its tests (proposed `engine/`), plus a new Compose service. Laravel application code, jobs, scheduler entries, and the PHP backtest engine are removed in a late apply phase, not in this proposal, except `CancelBotOrders` which is retained.
- Docs: `README.md`, `docs/runtime.md`, `docs/exchanges.md`, `docs/deployment.md` (note only; no prod Compose edit yet), `docs/backtesting.md`, `docs/risk.md`, `docs/security.md`.
- This change does not modify `openspec/changes/add-qnap-ci-deploy/` or production/CI files. Those must later add the engine image, Compose service, health checks, and `go test` in CI. Until then, a phase-8 SHA must not be deployed to QNAP from `origin/main`.
- Out of scope for this change: enabling live trading, hitting live exchange endpoints, changing the 5s browser poll to WebSockets, a per-bot tick-interval UI, and finishing QNAP deploy.

## Confirmed decisions

Johnson confirmed: Go writes the trading tables listed in the design; Laravel owns all migrations; hard cutover with no PHP engine fallback; backtesting moves to Go; this change proceeds ahead of the unfinished QNAP work without editing that change.

Johnson later confirmed he will not use the current PHP engine. Those workers are already stopped, and the remaining swap is direct: delete the PHP engine in phase 8. No drain-Horizon runbook, adopt-open-orders-from-PHP path, or no-overlap operator runbook is required. The kill-switch emergency cancel via `CancelBotOrders`, the live gate, the phase 8 QNAP release gate, and engine restart recovery for the engine's own open orders remain.

After review, Johnson also confirmed: an expired engine heartbeat is a warning only (no automatic pause, cancel, or kill-switch); the kill-switch and live-stop emergency cancel is user-triggered and runs in Laravel via `CancelBotOrders` when the heartbeat is missing or expired or Redis `XADD` fails; `desired_status` is split from runtime `status`, and `last_error` is engine-owned.
