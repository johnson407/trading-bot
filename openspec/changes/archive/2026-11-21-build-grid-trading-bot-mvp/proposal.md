# Proposal

## Why

The repository currently contains only the product requirements document; no application code exists. The MVP needs a maintainable Laravel foundation that lets an experienced developer or quantitative trader create, run, and monitor automated grid-trading bots in paper or live mode. Delivering the full MVP in one scoped change establishes the domain model, execution loop, exchange boundary, and safety controls together, so later strategies and features can be added without reworking core behavior.

## What Changes

- Scaffold a Laravel 12 application with Inertia.js + Vue 3, Laravel Breeze authentication, Redis queues, Horizon, and a Docker Compose local environment.
- Introduce a pluggable `StrategyInterface` and four concrete grid strategies: Simple, Trailing, Martingale, and Dynamic.
- Implement the grid lifecycle: generate levels around a center price, place limit orders, react to fills by placing the opposite-side order, and track base/quote inventory plus realized and unrealized P&L.
- Add exchange connectivity through a thin CCXT adapter with Binance as the default exchange, a paper-trading simulator driven by public market data, and optional live order execution with encrypted API credentials.
- Persist bot, grid, order, fill, strategy configuration, risk limit, and activity-log state so bots survive worker restarts, with a queued tick loop and reconciliation on startup.
- Build an authenticated dashboard: bot creation wizard, bot list, bot detail with grid visualization and P&L, start/pause/stop controls, and an activity log.
- Add risk controls: per-bot position and open-order caps, daily loss auto-pause, a user-wide kill-switch, and explicit live-mode confirmation with balance checks.
- Add structured logging, queue monitoring, and validation/test coverage for strategy math, the order state machine, and the paper adapter.

## Capabilities

### New Capabilities

- `strategy-engine`: strategy contract, pluggable strategy resolution, and the Simple, Trailing, Martingale, and Dynamic grid strategies including persisted strategy state.
- `grid-trading`: grid generation, level and order lifecycle, fill handling, inventory accounting, P&L calculation, and bot start/pause/stop behavior.
- `exchange-connectivity`: CCXT exchange adapter, exchange selection, encrypted credential storage, live order execution, and the paper-trading simulator.
- `bot-runtime`: queued tick processing, job idempotency, restart recovery and reconciliation, scheduling, and execution observability.
- `risk-controls`: per-bot exposure and open-order limits, daily loss limits, automatic pausing, live-mode safeguards, and the account-wide kill-switch.
- `trading-dashboard`: authenticated Inertia + Vue pages for bot creation, bot listing, bot detail and grid visualization, controls, P&L display, and activity logs.

### Modified Capabilities

None. This is the first change in the repository; no specs exist yet.

## Impact

- **New application:** Laravel 12 application skeleton under `app/`, `routes/`, `resources/`, `database/`, and `config/`, with Docker Compose services for PHP, Nginx, Redis, and MySQL/PostgreSQL.
- **Dependencies:** `ccxt/ccxt`, `inertiajs/inertia-laravel`, `laravel/breeze`, `laravel/horizon`, Redis client, and the Vue 3 frontend toolchain.
- **Data model:** bots, grids, grid levels, orders, fills, strategy configurations, risk limits, exchange credentials, and activity logs, with migrations and factories.
- **Runtime:** Redis queues and Horizon workers running `RunBotTick`, `PlaceOrder`, and `SyncOrders`; scheduler entries for health checks and balance sync.
- **Security:** encrypted exchange credentials, CSRF-protected and rate-limited endpoints, and redaction of secrets from logs.
- **Testing:** unit tests for strategy math and the order state machine, feature tests for paper trading and bot controls, and dashboard tests.
