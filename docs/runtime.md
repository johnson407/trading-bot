# Runtime Runbook

## Components

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php-fpm` behind Nginx | Inertia/Vue dashboard and API endpoints |
| Queue worker | `php artisan horizon` | Runs `RunBotTick`, `PlaceOrder`, and `SyncOrders` |
| Scheduler | `php artisan schedule:work` | Runs the one-second bot due-check plus scheduled health, order, and balance work |
| PostgreSQL | `postgres` | Durable bots, orders, fills, inventory, risk, and activity data |
| Redis | `redis-server` | Queues, cache, unique locks, and Horizon metrics |

The scheduler and Horizon must each run as a single supervised process. Do not scale
`schedule:work` horizontally: Laravel's scheduler is designed to run once per
application, and the per-bot locks are the second line of defence.

## First start

### Local setup without Docker

```bash
cp .env.example .env
chmod 600 .env   # non-Docker only: the local PHP process runs as the file owner
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force   # optional demo user/bot
php artisan horizon
php artisan schedule:work
```

`.env` holds database and exchange settings, so keep it owner-readable only
(mode `600`) when non-Docker processes run as your host user.

### Docker Compose

In Docker Compose these commands are wired into the entrypoint. Every service
waits for PostgreSQL and Redis, but only `app` has `RUN_MIGRATIONS=true`, so only
it runs `migrate --force` and `storage:link`. Horizon and the scheduler start
after `app` is healthy and never migrate concurrently. The same gate applies to
the production stack; production additionally refuses to start without a runtime
`APP_KEY`.

Under the bind-mounted Docker Compose setup, leave `.env` at mode `644`. PHP-FPM,
Horizon, and the scheduler run as Alpine `www-data` rather than your host user,
so a `600` file owned by your host user is unreadable inside the container.
Laravel then falls back to defaults (for example, Application Name and debug
settings, plus any keys Compose does not inject). Do not run `chmod 600 .env` on
a running Docker deployment; host-side environment injection was considered for
this setup and dropped for this change.

## Tick loop

1. `bots:dispatch` runs every second as a cheap due-check. It selects `running`
   bots, skips a bot whose `last_tick_at` is newer than its
   `tick_interval_seconds`, and dispatches `RunBotTick` with uniqueness key
   `bot-tick:{botId}`. New bots default their interval to
   `TRADING_TICK_INTERVAL` (60 seconds).
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

## Cadences and tick-time latency

Four cadences are distinct:

- `bots:dispatch` runs every second, but only as a due-check; it does not mean
  every bot evaluates every second.
- Each bot evaluates at its stored `tick_interval_seconds`, which defaults to
  `TRADING_TICK_INTERVAL` (60 seconds) for new bots. The one-second minimum is
  unchanged.
- Health checks run every 30 seconds (`trading.health.check_interval_seconds`),
  order sync every 10 seconds, and balance sync every 60 seconds
  (`trading.balances.sync_interval_seconds`). These schedules are unchanged.
- The dashboard polls every five seconds. The poll only reads persisted state; it
  does not trigger a tick.

Paper fills and daily-loss evaluation happen only when a tick runs. At the
60-second default, a price that crosses and recrosses a paper order's limit
between ticks is not guaranteed to fill, and a daily-loss breach may be acted on
up to about one interval late. Consecutive-failure pausing also takes longer in
wall-clock time because failures are counted per tick; the failure threshold
itself is unchanged. The kill switch still takes effect immediately.

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
`bots:health` pauses running bots whose last successful tick is older than the
effective stale threshold: `max(trading.tick.stale_after_seconds,
tick_interval_seconds + trading.health.check_interval_seconds)`, with
`tick_interval_seconds` floored at one second. The default threshold is 180
seconds. It records `bot.stale` plus `risk.stale_bot`. `bots:sync-balances` refreshes live-bot base/quote inventory from
the exchange. The scheduler runs health checks every
`trading.health.check_interval_seconds` (30s) and balance sync every
`trading.balances.sync_interval_seconds` (60s). When more than one live bot shares an exchange account, balance sync is skipped for those bots: per-bot inventory stays authoritative and a single `bot.balance_sync_skipped` activity is written per bot per day.

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

The capacity harness stress target is ten bots at a one-second tick interval.
This is an execution-budget load test, not the production default; new bots ship
on a 60-second interval.

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
worker pool from production metrics and keep the harness's one-second cadence
best-effort. If a round exceeds the budget, the dispatcher skips stale ticks
instead of building a backlog.

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

## Upgrading an existing deployment

The 60-second interval and 180-second stale threshold are new defaults, not
overrides of a live `.env`. If an existing `.env` pins `TRADING_TICK_INTERVAL=1`
or `TRADING_STALE_TICK_SECONDS=60`, edit those two keys in the running
environment yourself, then restart the scheduler and Horizon workers so the
cached config and worker processes pick up the change. Do not edit `.env`
automatically as part of a deploy.

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


## Startup and dispatch guards

The scheduler container runs `php artisan bots:reconcile` before `schedule:work`,
so a restart reconciles running bots before any new tick is dispatched. If
reconciliation reports conflicts the scheduler still starts; each affected bot
remains paused.

`bots:dispatch` writes a short-lived `bot-dispatch:{id}` cache key for the bot's
interval before queueing, so queue latency cannot produce duplicate ticks for
the same second. Exchange clients are reused per exchange and credential within
a worker. The stale-tick threshold defaults to 180 seconds
(`TRADING_STALE_TICK_SECONDS`), or to the bot's interval plus the health-check
interval when that is longer, so a slow initial live grid is not paused mid-work.
