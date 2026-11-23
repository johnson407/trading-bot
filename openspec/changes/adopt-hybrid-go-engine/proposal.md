# Proposal

## Why

The trading engine today runs inside Laravel: a one-second `bots:dispatch` due-check, Horizon jobs (`RunBotTick`, `PlaceOrder`, `SyncOrders`), REST `fetch_ticker` market data, PHP strategies, and a PHP backtest engine. That shape is a poor fit for a long-lived Binance WebSocket, tight per-bot concurrency, and a single long-running process that must stay up independently of HTTP workers. Johnson has confirmed Option A: keep Laravel + Inertia + Vue for the UI, strategy configuration, monitoring, and admin; move the core engine — WebSocket market data, grid calculation, order logic, the tick loop, and backtesting — into a Go service, with a hard cutover (the PHP engine is removed, no fallback flag).

The trade-off is real: two languages, two deployable units, and a rewrite of the hottest path. The mitigation is a contract-first design (Postgres as the system of record, versioned Redis Streams, a table-ownership matrix), golden tests captured from the current PHP behaviour before that code is deleted, and a phased apply with proof at each step. Live trading stays gated and is never exercised during apply.

## What Changes

- Introduce a Go `engine` service that owns runtime evaluation, market data, grid and order logic, paper and (gated) live execution, risk enforcement at trade time, reconciliation, live balance sync, and backtest replay.
- Laravel remains the management plane: authentication, bot CRUD, configuration validation, credential storage, dashboard, alerts, analytics reads/snapshots, and all migrations/schema. Laravel reads trading tables; it does not write orders, fills, grids, inventory, or engine strategy state.
- Replace PHP tick dispatch and Horizon tick/order/backtest jobs with Redis Streams commands (start, pause, stop, cancel, kill-switch, backtest-request) and engine events/heartbeats. The kill-switch is immediate via Redis; Postgres bot status is the fallback if Redis is unavailable.
- Binance WebSocket for trading market data, with reconnect, gap detection, and REST fallback. Paper fills keep the current `PaperExchangeClient::qualifyingFills` semantics (evaluated from the market last at tick time).
- Local `docker-compose.yml` gains an `engine` service. Horizon and the Laravel scheduler are trimmed to leftover management work (alerts, analytics). Production Compose and the CI image build are deferred and noted so they can land after `add-qnap-ci-deploy`.
- Port `TRADING_LIVE_ENABLED`, `LiveModeGuard`, `RiskGuard`, `DailyLossMonitor`, the exit guard, `ConsecutiveFailureTracker`, and the exchange allowlist into Go as the single blocking pre-trade implementation. No live-to-paper fallback. Go decrypts Laravel-encrypted credentials with the shared `APP_KEY` and never logs secrets.
- Remove the PHP tick pipeline, strategies, order services, sync/reconcile jobs, related scheduler entries, and the PHP backtest engine after golden fixtures are captured and the Go port is proven.

## Capabilities

### New Capabilities

None. Runtime, connectivity, grid, strategy, risk, backtesting, and dashboard behaviour are specified as deltas on the existing capabilities.

### Modified Capabilities

- `bot-runtime`: the Go engine, not Laravel Horizon, evaluates ticks, reconciles, and maintains heartbeats; command/event Streams and existing tick/stale semantics are required.
- `exchange-connectivity`: WebSocket market data with REST fallback; credential use by the engine; paper-fill parity retained.
- `grid-trading`: start/pause/stop are Laravel commands executed by the engine.
- `strategy-engine`: lifecycle and state persistence run in the engine; Laravel stores configuration only.
- `risk-controls`: engine-side enforcement; kill-switch immediacy via Redis plus DB fallback.
- `backtesting`: replay and sweeps run in the engine; the Laravel UI submits requests and reads results.
- `trading-dashboard`: five-second polling is unchanged; engine health is visible.

## Impact

- New tree for the Go module and its tests (proposed `engine/`), plus a new Compose service. Laravel application code, jobs, scheduler entries, and the PHP backtest engine are removed in a late apply phase, not in this proposal.
- Docs: `README.md`, `docs/runtime.md`, `docs/exchanges.md`, `docs/deployment.md` (note only; no prod Compose edit yet), `docs/backtesting.md`, `docs/risk.md`, `docs/security.md`.
- This change does not modify `openspec/changes/add-qnap-ci-deploy/` or production/CI files. Those must later add the engine image, Compose service, and health checks.
- Out of scope for this change: enabling live trading, hitting live exchange endpoints, changing the 5s browser poll to WebSockets, a per-bot tick-interval UI, and finishing QNAP deploy.

## Confirmed decisions

Johnson confirmed: Go writes the trading tables listed in the design; Laravel owns all migrations; hard cutover with no PHP engine fallback; backtesting moves to Go; this change proceeds ahead of the unfinished QNAP work without editing that change.
