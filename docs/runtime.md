# Runtime Runbook

## Components

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php-fpm` behind Nginx | Inertia/Vue dashboard and API endpoints |
| Queue worker | `php artisan horizon` | Runs `RunBotTick`, `PlaceOrder`, and `SyncOrders` |
| Scheduler | `php artisan schedule:work` | Dispatches bot ticks, health checks, and balance sync every second/minute |
| PostgreSQL | `postgres` | Durable bots, orders, fills, inventory, risk, and activity data |
| Redis | `redis-server` | Queues, cache, unique locks, and Horizon metrics |

The scheduler and Horizon must each run as a single supervised process. Do not scale
`schedule:work` horizontally: Laravel's scheduler is designed to run once per
application, and the per-bot locks are the second line of defence.

## First start

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force   # optional demo user/bot
php artisan horizon
php artisan schedule:work
```

In Docker Compose these commands are already wired into the `app`, `horizon`, and
`scheduler` services. The entrypoint waits for PostgreSQL and Redis, runs
`migrate --force`, and then starts the service's command.

## Tick loop

1. `bots:dispatch` runs every second. It selects `running` bots, skips a bot whose
   `last_tick_at` is newer than its `tick_interval_seconds`, and dispatches
   `RunBotTick` with uniqueness key `bot-tick:{botId}`.
2. `RunBotTick` is unique for 10 seconds and carries a `WithoutOverlapping` lock
   (`bot-tick:{botId}`) that expires after 30 seconds. A duplicate delivery or a
   slow worker drops the tick rather than overlapping it.
3. `BotTickPipeline` records `last_tick_at`, enforces the live gate, fetches market
   data, evaluates daily loss with the current mark price (realized plus
   unrealized), evaluates the strategy, applies risk checks, places orders,
   persists strategy state, and writes an execution log.
4. A successful tick updates `last_successful_tick_at` and resets consecutive
   failures. A failure increments the failure counter and is logged as
   `bot.tick_failed`; at the configured threshold the bot pauses and its open
   orders are cancelled.

There is no unbounded queue growth: an in-flight unique tick suppresses newer
dispatches, and stale ticks are skipped by the dispatcher.

## Recovery

On a fresh host, run:

```bash
php artisan bots:reconcile
```

The command resumes `running` bots, reloads strategy state, and compares local
orders and balances with the exchange. Pending orders without an exchange id are
matched by client order id. Matching orders are retained; partial and full remote
fills are applied through the normal fill pipeline and strategy handler, so paired
orders are placed; canceled orders are updated; extra remote orders and balance
differences outside `trading.reconciliation.tolerance` pause the bot and record
`risk.reconciliation_conflict`. A paused bot requires an explicit start and never
resumes automatically.

Paper bots are skipped by reconciliation because their ledger is authoritative.

## Health and balances

```bash
php artisan bots:sync-orders
php artisan bots:health
php artisan bots:sync-balances
```

`bots:sync-orders` runs every 10 seconds and resolves live pending/open orders
against the exchange. An ambiguous placement is adopted by client order id;
new fills are applied through `FillProcessor`, then the strategy receives
`onFill`, the grid is synchronized, and the paired opposite order is placed.
`bots:health` pauses running bots whose last successful tick is older than
`trading.tick.stale_after_seconds` and records `bot.stale` plus
`risk.stale_bot`. `bots:sync-balances` refreshes live-bot base/quote inventory from
the exchange. The scheduler runs health checks every
`trading.health.check_interval_seconds` (30s) and balance sync every
`trading.balances.sync_interval_seconds` (60s).

## Queue operations

```bash
php artisan horizon:status
php artisan horizon:terminate        # graceful restart after deploys
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush              # only after reviewing failed jobs
php artisan queue:monitor redis:default
```

Horizon is configured in `config/horizon.php`. Keep the `default` supervisor
running with enough processes for ten concurrent bots; each tick performs one
small exchange call and several short database transactions. For a small
deployment, 2–4 workers are sufficient because the per-bot lock prevents overlap.

Use `php artisan horizon:snapshot` on a schedule if you retain Horizon metrics
outside Redis. Failed jobs should be inspected before retrying: exchange errors
may already have been retried with backoff inside the CCXT client.

## Reference capacity profile

The MVP target is ten bots at a one-second tick cadence.

Reference profile used for the capacity harness:

- 2 vCPU, 2 GB RAM, Linux x86_64
- PHP 8.4 CLI (static build) with OPcache disabled in the harness
- PostgreSQL 18 and Redis 8 on localhost
- Fake exchange client (no network latency), paper mode
- 10 running bots, 3 rounds, 30 ticks per harness run

Result: 30 ticks completed with 0 errors; the slowest round remained under the
1,000 ms budget (`CapacityReport::withinBudget` true). The harness intentionally
does not sleep between rounds, so round elapsed time is the schedule-drift signal.

This is a deterministic domain/runtime harness, not a production exchange
benchmark. Real CCXT latency and rate limits dominate live performance; size the
worker pool from production metrics and keep the one-second cadence best-effort.
If a round exceeds the budget, the dispatcher skips stale ticks instead of
building a backlog.

Run the harness in tests:

```bash
php artisan test tests/Feature/CapacityHarnessTest.php
```

## Logs and observability

- Structured ticks: `bot.tick` with bot id, symbol, price, orders, fills,
  rejected orders, and duration.
- Failures: `bot.tick_failed` with the exception message and trace; secrets are
  scrubbed by `App\Logging\RedactSecrets`.
- Activity log: bot lifecycle, orders, fills, reconciliation, health, and risk
  events are queryable per bot and per user.
- Horizon metrics: queue wait, throughput, and failed jobs.

## Deployment checklist

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan horizon:terminate
```

Restart Horizon and the scheduler after every deploy. Confirm
`TRADING_LIVE_ENABLED=false` unless live trading has been explicitly approved.
