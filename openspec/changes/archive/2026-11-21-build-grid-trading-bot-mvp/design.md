# Design

## Context

The repository has no application code. The PRD (v1.2) locks the stack: PHP/Laravel 12, Inertia.js + Vue 3 with Breeze, CCXT for exchange connectivity, Redis with Horizon for queues, and Docker Compose locally. The design must deliver a maintainable MVP that supports ten bots at one-second ticks, paper trading by default, optional live Binance trading, and durable state across restarts. See `proposal.md` for motivation and `specs/` for required behavior.

## Goals / Non-Goals

**Goals:**

- Establish domain boundaries that keep strategy math, grid/order state, exchange I/O, and risk enforcement independently testable.
- Define a persistent data model and an execution model that survives worker restarts and duplicate queue deliveries.
- Make paper and live trading share one order/fill pipeline so behavior cannot diverge.
- Enforce risk limits in the engine, not only in the UI.
- Keep the live path explicitly opt-in and secrets protected.

**Non-Goals:**

- Multi-tenant SaaS concerns, team permissions, billing, or horizontal scaling beyond the MVP capacity target.
- Real-time WebSocket UI, advanced charting, or a technical-indicator library.
- Custom exchange adapters outside CCXT, DEX trading, or asynchronous exchange I/O.
- Backtesting, historical replay, or portfolio-level strategies.

## Decisions

### Application structure

Use Laravel 12 with a domain-oriented layout inside the existing framework conventions:

```text
app/
  Domain/
    Bot/            Bot aggregate, state transitions, BotState
    Grid/           Grid, GridLevel, grid generation policies
    Order/          Order state machine, Fill recorder
    Portfolio/      Inventory and P&L calculators
    Risk/           RiskGuard, limits, risk events
    Strategy/       StrategyInterface, DTOs, StrategyRegistry
    Exchange/       ExchangeClient contract, value objects, errors
  Strategies/       SimpleGrid, TrailingGrid, MartingaleGrid, DynamicGrid
  Services/Exchange/
    CcxtExchangeClient.php
    PaperExchangeClient.php
  Jobs/             RunBotTick, PlaceOrder, SyncOrders
  Actions/          StartBot, PauseBot, StopBot, ActivateKillSwitch
  Http/             Thin controllers, form requests, resources
  Models/           Eloquent persistence models
```

Controllers and jobs orchestrate; domain objects own behavior. Eloquent models stay persistence-focused and delegate state transitions to domain services. This keeps the order state machine and strategy math unit-testable without the database or network.

**Alternative considered:** fat Eloquent models with observers. Rejected because order/fill transitions and risk checks would be hard to test and easy to bypass.

### Money and precision

Represent all prices, quantities, balances, and P&L as decimal strings and persist them as `DECIMAL(32,16)` columns. Use `bcmath` for arithmetic through a small `Decimal` helper. Never use PHP floats for monetary values.

**Alternative considered:** integer minor units. Rejected because crypto quantities and prices have exchange-defined precision that varies per symbol and is easier to express with decimals.

### Data model

Core tables:

- `users` from Breeze.
- `exchange_credentials`: user, exchange, encrypted key/secret/passphrase, testnet flag, timestamps. Unique per user and exchange.
- `bots`: user, name, exchange, symbol, strategy key, mode (`paper`/`live`), status (`idle`/`running`/`paused`/`stopped`/`error`), capital allocation, base/quote inventory, realized P&L, last tick timestamp, last error, timestamps.
- `strategy_configs`: bot, strategy key, configuration JSON, state JSON, state version. One current row per bot; state updates are versioned.
- `grids`: bot, center price, spacing type (`arithmetic`/`geometric`/`dynamic`), level count, spacing value, status, timestamps.
- `grid_levels`: grid, sequence, price, side, state (`idle`/`open`/`filled`/`canceled`), timestamps. Unique on grid and sequence.
- `orders`: bot, grid level, client order id (unique), exchange order id, mode, side, type, price, quantity, filled quantity, status, timestamps.
- `fills`: bot, order, exchange fill id (unique per exchange), price, quantity, fee, filled at.
- `risk_limits`: scope (`user`/`bot`), user, optional bot, max position notional, max open orders, daily loss limit, day start, timestamps.
- `risk_events` and `activity_logs`: bot/user, type, severity, message, context JSON, created at.
- Laravel infrastructure tables for jobs, failed jobs, cache locks, and sessions.

Use PostgreSQL 16. It provides reliable `DECIMAL` arithmetic, `JSONB` for strategy state and event context, and strong concurrent index behavior. Indexes cover `orders(status)`, `orders(bot_id, status)`, `fills(bot_id, filled_at)`, `bots(status)`, and unique keys for idempotency.

**Alternative considered:** MySQL. It is viable and allowed by the PRD, but PostgreSQL's JSONB and stricter numeric behavior reduce friction for strategy state and reconciliation queries.

### Strategy contract

`StrategyInterface` exposes the six PRD methods: `initialize`, `onTick`, `onFill`, `onCancel`, `shouldStop`, and `getGridLevels`. Strategies are pure decision-makers: they receive immutable value objects (market snapshot, bot state, strategy state, configuration) and return decisions (desired levels, order intents, stop decision, next state). They do not read the database or call exchanges.

Register strategies in a `StrategyRegistry` keyed by a stable identifier (for example `simple-grid`) and resolve them through the Laravel container. `strategy_configs.strategy_key` stores the identifier. Adding a strategy means adding a class and registering it.

Strategy state is a JSON document with a version field. `initialize` is idempotent: it only creates state when none exists. Each tick persists the returned state after the tick's order and fill work commits, so a crash cannot leave orders applied without their state.

**Alternative considered:** strategies as Eloquent models or services with direct repository access. Rejected because it couples strategy math to persistence and makes deterministic unit testing harder.

### Grid, order, and fill lifecycle

`GridGenerator` builds levels from the strategy's plan. Arithmetic levels use fixed price spacing; geometric levels use a percentage; dynamic spacing comes from the strategy. Invalid levels (zero/negative or duplicate prices) are rejected before persistence.

Orders follow an explicit state machine:

```text
pending -> open -> partially_filled -> filled
                 \-> canceled
pending/open -> rejected
```

Only `OrderService` performs transitions, validating the current state and writing a domain event/activity record. Each order gets a deterministic `client_order_id` derived from bot, grid, level, and cycle. The pair `(bot_id, client_order_id)` is unique, making placement idempotent and preventing duplicate orders under job re-delivery.

Fills use the exchange fill identifier as the idempotency key (`unique(exchange, exchange_fill_id)`). A fill applies exactly once: record fill, update order, update inventory, and request the opposite-side level order. The whole handler runs in one database transaction with `lockForUpdate` on the bot and affected level/order rows.

### Exchange boundary

Define an `ExchangeClient` contract with market data, balance, order placement, cancellation, and order-status methods. Two implementations share the contract:

- `CcxtExchangeClient` wraps `ccxt/ccxt`, constructs the exchange with credentials only when private calls are required, and maps CCXT exceptions to a normalized error set.
- `PaperExchangeClient` uses CCXT public endpoints for prices and rules, simulates fills from observed prices, and throws if a private or order-placing call is attempted.

The bot's mode selects the implementation at runtime; jobs never branch on exchange specifics. CCXT's built-in rate limiter is enabled, and the wrapper adds bounded retries with backoff for transient failures. After a configurable number of consecutive failures, a bot is paused and an error is recorded; there is no silent fallback from live to paper.

Paper simulation rule: at each tick, compare the latest price with open paper orders. Buy orders fill when `price <= limit`, sell orders when `price >= limit`. When several levels qualify, process them in market-price order. Fills occur at the limit price. Fees are configurable per bot and default to zero for paper mode.

**Alternative considered:** a separate paper-trading engine. Rejected because sharing the order/fill pipeline is what makes paper mode a meaningful rehearsal for live mode.

### Execution and scheduling

Use Laravel's scheduler running as `php artisan schedule:work` in its own container, with `everySecond()` dispatching `RunBotTick` for bots whose `last_tick_at` is older than their interval. `RunBotTick` implements `ShouldBeUnique` and acquires a per-bot cache lock (`WithoutOverlapping`) so a bot never has two ticks in flight.

Each tick performs, in order: load bot and strategy state, fetch market data, reconcile any known exchange order updates, evaluate the strategy, apply order intents through risk checks, apply fill handling, persist state and `last_tick_at`, emit a structured execution record.

`PlaceOrder` and `SyncOrders` are separate queued jobs for retries and reconciliation. All jobs are idempotent. If the queue backs up, the unique/lock behavior drops duplicate ticks rather than queuing unbounded work, and the health check surfaces bots whose last successful tick is stale.

**Alternative considered:** one long-running daemon per bot. Rejected for the MVP because process supervision, restart semantics, and deployment are more complex than the queue model, while the capacity target is modest.

### Startup recovery

On application boot, a `ReconcileBots` command resumes bots marked running, reloads strategy state, and compares local open orders and balances with the exchange (or the paper ledger). Matching orders are retained; missing, extra, or filled orders are updated from the exchange; balances outside tolerance pause the bot with a conflict event. Reconciliation must complete before the bot's next tick.

### Risk enforcement

`RiskGuard` is invoked by the order pipeline for every order intent and by the tick pipeline for daily loss evaluation. It checks per-bot max position notional, max open orders, and applicable daily loss limits. Limits are non-bypassable: strategies return intents, and only the pipeline can create orders. The kill-switch is an action that pauses all of a user's bots and cancels their open orders in parallel, recording per-bot failures.

Live-mode safety: a config flag (`trading.live_enabled`, default false) gates live execution in addition to per-bot confirmation and the balance check. This prevents accidental live trading in local or staging environments even if a bot is misconfigured.

### P&L and inventory

Track base and quote inventory per bot as decimals. Realized P&L uses average cost: buys increase the cost basis, sells realize proceeds minus the average cost of the quantity sold. Unrealized P&L is `(mark price - average entry price) * open base position`, floored at zero position. Fees reduce realized P&L. Every fill writes an inventory ledger entry (derived from fills) so P&L can be recomputed and audited.

### Dashboard

Use Inertia.js + Vue 3 with Breeze authentication, Vite, and Tailwind. Pages: bot list, create-bot wizard, bot detail, exchange credentials, activity log, kill-switch confirmation. Controllers stay thin and return Inertia responses; form requests own validation; API resources shape bot/order/fill data. The detail and list pages poll every 3–5 seconds using Inertia's polling helper, meeting the five-second freshness target without WebSockets.

Grid visualization is a lightweight SVG/CSS ladder showing levels, current price, and order state; no charting dependency is added.

### Security

- Encrypt credentials with Laravel's `encrypted` cast using `APP_KEY`; mark them `$hidden` and never return them in resources.
- Redact known secret fields in logs through a Monolog processor and CCXT debug settings.
- Require authentication and per-user authorization policies on every bot, credential, and action.
- Rate-limit authentication, bot-control, and kill-switch endpoints.
- CSRF protection stays enabled for all Inertia mutations.
- Live mode is disabled by default at the configuration level.

### Testing

- Unit: grid generators, each strategy's levels and state transitions, P&L/inventory math, order state machine, risk guard, paper fill rules. Use fixed decimal fixtures and assert exact strings.
- Feature: bot CRUD and authorization, mode confirmation, start/pause/stop, kill-switch, credential masking, tick job idempotency, duplicate fill handling, restart recovery with a fake exchange client.
- Contract: a fake `ExchangeClient` for deterministic engine tests; one opt-in integration test suite for CCXT testnet, excluded from the default run.
- Target >90% coverage on strategy and order/P&L domain code.
- Use Pest 3 on top of PHPUnit for concise tests, with Laravel's testing helpers.

## Risks / Trade-offs

- **CCXT PHP is synchronous and memory-heavy** → run all exchange calls in queued jobs, keep one client per job execution, and avoid loading CCXT in web requests.
- **One-second scheduling with a database-backed tick check can drift** → use per-bot locks, skip stale ticks instead of queuing them, and add a stale-bot health check; document that `schedule:work` must run as a supervised container.
- **Decimal string arithmetic is slower and easier to misuse than floats** → centralize it in a `Decimal` helper with unit tests, and forbid floats in domain code.
- **Paper fills at the limit price are optimistic** → document the simplification, make fees configurable, and keep the simulated fill rule explicit so live behavior is never assumed identical.
- **Reconciliation can pause bots unexpectedly after a crash** → treat pausing as the safe outcome, record precise conflicts, and require explicit resume.
- **Polling the dashboard every few seconds adds load** → cap the bot list page size, use lightweight summary endpoints, and keep polling interval at five seconds or slower when idle.
- **Live trading bugs can lose capital** → configuration-level live gate, explicit confirmation, balance check, hard risk caps, kill-switch, and paper-first defaults.

## Migration Plan

This is a greenfield deployment; there is no existing data to migrate.

1. Scaffold Laravel 12, install Breeze (Inertia + Vue), Horizon, CCXT, and configure PostgreSQL/Redis.
2. Add Docker Compose services for app, Nginx, PostgreSQL, Redis, Horizon, and the scheduler; document startup and the default paper-mode configuration.
3. Run migrations and seeders for a demo user and default risk limits.
4. Ship with `TRADING_LIVE_ENABLED=false`; live mode is enabled only in an environment where credentials and testnet verification have been completed.
5. Rollback: stop the scheduler and Horizon, then remove the deployment. Paper-mode data is disposable; live mode must be disabled and open orders canceled through the kill-switch before teardown.

## Open Questions

None. Fee defaults, tick cadence, and database choice are resolved above; remaining tuning values (failure thresholds, reconciliation tolerance) are configuration defaults that can change without altering the specs or task breakdown.
