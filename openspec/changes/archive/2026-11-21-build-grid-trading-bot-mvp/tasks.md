# Tasks

## 1. Project Foundation

- [x] 1.1 Scaffold a Laravel 12 application with Breeze (Inertia + Vue 3) and Horizon; verify `composer install`, `npm install && npm run build`, and `php artisan about` succeed.
- [x] 1.2 Install `ccxt/ccxt`, configure PostgreSQL and Redis connections, and add the `trading.live_enabled` flag (default false) plus decimal/fee settings; verify `php artisan config:show trading` and successful PostgreSQL and Redis connection checks.
- [x] 1.3 Add Docker Compose services for app, Nginx, PostgreSQL, Redis, Horizon, and the scheduler; verify `docker compose config` is valid and `docker compose up -d` reports every service healthy.
- [x] 1.4 Configure UTC storage, structured logging, and a secret-redaction processor for known credential fields; verify a test that logs a fake API secret and asserts it is redacted.
- [x] 1.5 Install and configure Pest with a base test setup and a `composer test` script; verify `composer test` runs an initial smoke test successfully.

## 2. Data Model and Domain Primitives

- [x] 2.1 Implement a bcmath-backed decimal helper for add, subtract, multiply, divide, compare, and rounding; verify unit tests assert exact string results and reject floating-point input.
- [x] 2.2 Create migrations for bots, strategy configurations, grids, grid levels, orders, fills, exchange credentials, risk limits, risk events, and activity logs with the indexes and unique keys from design.md; verify `php artisan migrate:fresh` succeeds and the expected indexes exist.
- [x] 2.3 Create Eloquent models with decimal casts, relationships, guarded/hidden fields, and status enums; verify model tests cover relationships and secret fields are absent from serialization.
- [x] 2.4 Add factories and seeders for a demo user, paper bot, strategy configuration, risk limits, and sample orders; verify `php artisan migrate:fresh --seed` produces a runnable demo dataset.
- [x] 2.5 Implement the activity and risk-event recorder with bot/user attribution and JSON context; verify tests assert event type, severity, and context are persisted and readable.

## 3. Strategy Engine

- [x] 3.1 Define strategy value objects, `StrategyInterface`, and a registry resolved through the container; verify a contract test covers initialization, tick, fill, cancel, stop, and grid-level decisions for a fake strategy.
- [x] 3.2 Implement the Simple grid strategy with arithmetic and geometric spacing; verify unit tests cover both spacing modes, invalid spacing, and duplicate-level rejection.
- [x] 3.3 Implement the Trailing grid strategy with trailing-distance recentering and order replacement; verify tests cover recentering beyond the distance, no movement within it, and replacement of out-of-range orders.
- [x] 3.4 Implement the Martingale grid strategy with a size multiplier, maximum size, and maximum increase count; verify tests cover successive increases, cap enforcement, and the cap event.
- [x] 3.5 Implement the Dynamic grid strategy using recent volatility with minimum/maximum clamping and a recalculation interval; verify tests cover widening, narrowing, clamping, and interval suppression.
- [x] 3.6 Implement strategy-state serialization with a version field and idempotent initialization; verify tests resume from persisted state and pause with an error when state is unusable.
- [x] 3.7 Write `docs/strategies.md` explaining the strategy contract, registration, state rules, and a minimal custom strategy; verify the documented example class can be registered and resolved in a test.

## 4. Grid, Order, Fill, and Portfolio Engine

- [x] 4.1 Implement grid generation for arithmetic, geometric, and strategy-provided spacing with level validation; verify tests reject zero/negative prices, duplicate levels, and non-positive parameters.
- [x] 4.2 Implement the order state machine with allowed transitions, deterministic client order IDs, and uniqueness enforcement; verify tests cover legal/illegal transitions and duplicate placement attempts.
- [x] 4.3 Implement transactional fill processing with exchange-fill idempotency, order updates, and opposite-side order requests; verify tests cover buy fills, sell fills, duplicate fills, and rollback on failure.
- [x] 4.4 Implement inventory tracking and average-cost realized/unrealized P&L with an inventory ledger and negative-inventory protection; verify tests assert exact decimal P&L for multi-fill cycles and pause on a negative position.
- [x] 4.5 Implement start, pause, stop, and idempotent cancellation actions; verify tests cover pause preserving orders, stop canceling orders, resume without duplicates, and canceling an already terminal order.
- [x] 4.6 Add a domain integration test that runs a buy fill followed by the opposite sell fill; verify it produces one completed grid cycle with the expected inventory and realized P&L.

## 5. Exchange Connectivity

- [x] 5.1 Define the `ExchangeClient` contract, market/balance/order value objects, and normalized error types; verify contract tests cover every method and error mapping.
- [x] 5.2 Implement the CCXT client's public market data, trading rules, rate limiting, and bounded retry/backoff; verify tests with a mocked CCXT instance cover price, precision rules, rate-limit handling, and retry exhaustion.
- [x] 5.3 Implement live order placement, cancellation, status lookup, balance retrieval, and testnet selection through CCXT; verify mocked tests cover accepted orders, rejections, cancellations, balances, and testnet configuration.
- [x] 5.4 Implement the paper exchange client using public market data, the limit-crossing fill rule, configurable fees, and a guard that rejects private/order-placing calls; verify tests cover buy/sell fills, multiple qualifying levels, fees, and the private-call guard.
- [x] 5.5 Implement encrypted exchange credential storage, replacement, masking, and log redaction; verify tests assert ciphertext at rest, masked output, replacement behavior, and absent secrets in logs.
- [x] 5.6 Implement consecutive-failure tracking that pauses a bot, attempts cancellation, and records an error without changing its mode; verify tests cover the failure threshold, cancellation attempt, and no silent live-to-paper switch.
- [x] 5.7 Write `docs/exchanges.md` covering Binance setup, API-key permissions, testnet use, paper versus live behavior, and failure handling; verify every documented command and configuration key exists in the application.

## 6. Risk Controls

- [x] 6.1 Implement `RiskGuard` checks for maximum position notional and maximum open orders, returning a blocked decision and risk event; verify tests cover within-limit, boundary, and over-limit orders.
- [x] 6.2 Implement per-bot and global daily loss tracking with reset boundaries, automatic pause, and order cancellation; verify tests cover per-bot breach, global breach, reset, and paused bots staying paused.
- [x] 6.3 Implement the account-wide kill-switch with parallel cancellation and per-bot failure reporting; verify tests cover cancellation of all bots, partial failures, and immediate pausing.
- [x] 6.4 Implement live-mode safeguards: explicit confirmation data, the configuration-level live gate, and a pre-start balance check; verify tests cover missing confirmation, disabled live mode, sufficient balance, and shortfall.
- [x] 6.5 Expose risk limits and risk events through the domain layer for the dashboard; verify tests assert limits can be read/updated per bot and globally and that each breach is queryable.
- [x] 6.6 Write `docs/risk.md` documenting limit semantics, loss windows, kill-switch behavior, and live-mode prerequisites; verify each documented default matches the configuration files.

## 7. Bot Runtime and Recovery

- [x] 7.1 Implement `RunBotTick`, `PlaceOrder`, and `SyncOrders` as unique, idempotent queued jobs; verify tests re-deliver each job and assert no duplicate orders, fills, or inventory changes.
- [x] 7.2 Implement one-second scheduling with per-bot locking, interval checks, and stale-tick skipping; verify tests with time control assert cadence, overlap prevention, and no unbounded queue growth.
- [x] 7.3 Implement the tick pipeline (state load, market fetch, reconciliation, strategy evaluation, risk-checked orders, fill handling, persistence, execution record); verify an integration test drives a paper bot through a full tick.
- [x] 7.4 Implement startup recovery that resumes running bots, reloads state, and reconciles open orders and balances before ticking; verify fake-exchange tests cover matching, filled, canceled, and unmatched orders plus tolerance breaches.
- [x] 7.5 Implement scheduled health checks and balance synchronization; verify tests cover stale-bot detection, live balance refresh, and healthy bots producing no false alarms.
- [x] 7.6 Add a ten-bot capacity test harness at one-second intervals and document the hardware profile; verify the harness reports no persistent schedule drift on the documented environment.
- [x] 7.7 Write `docs/runtime.md` covering scheduler, Horizon workers, locking, recovery, health checks, and queue operations; verify the runbook commands work in the Docker environment.

## 8. Dashboard and Control Surface

- [x] 8.1 Configure Breeze authentication and add authorization policies for bots, credentials, and actions; verify feature tests reject guests, deny cross-user access, and allow owners.
- [x] 8.2 Build the bot creation flow with symbol, strategy, grid parameters, capital allocation, exchange, and mode validation plus paper default; verify feature tests cover valid creation and each invalid field.
- [x] 8.3 Build the bot list with state, mode, and P&L summaries plus five-second polling; verify feature tests assert payload contents and the built frontend renders the list.
- [x] 8.4 Build the bot detail view with grid visualization, open orders, recent fills, inventory, P&L, and five-second polling; verify feature tests assert the page data and a fill is reflected after the next poll.
- [x] 8.5 Build start, pause, and stop controls with live-mode confirmation and failure reporting; verify feature tests cover each action, canceled confirmation, and a failed action preserving the prior state.
- [x] 8.6 Build the kill-switch confirmation, exchange credential management with masked output, and the per-bot activity log; verify feature tests cover confirmation, masking, replacement, and reverse-chronological events.
- [x] 8.7 Document the dashboard flows in `docs/dashboard.md`; verify each documented flow matches a route and a feature test.

## 9. Integration and Hardening

- [x] 9.1 Run an end-to-end paper-bot scenario in Docker from bot creation through simulated fills and P&L display; verify the scripted walkthrough completes within the 15-minute success metric.
- [ ] 9.2 Add an opt-in CCXT testnet integration suite and verify it can place and cancel a small order when credentials and the testnet flag are provided.
- [x] 9.3 Perform a security review of authentication, authorization, credential encryption, log redaction, CSRF, and rate limiting; verify a written checklist with evidence for each control.
- [x] 9.4 Run the full test suite, static analysis, and a coverage report for domain code; verify all tests pass, analysis is clean, and strategy/order/P&L coverage exceeds 90%.
- [x] 9.5 Update the README and deployment runbook with setup, Docker commands, live-mode prerequisites, and the kill-switch drill; verify a fresh clone can follow the README to a running paper bot.

## Workflow follow-up

- Review the completed change against the specs and design before archiving.
- Archive the change with OpenSpec and verify the resulting capability specs under `openspec/specs/`.
