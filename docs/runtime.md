# Runtime Runbook

> The hybrid runtime is documented in [engine.md](engine.md). Laravel owns the UI,
> configuration, `desired_status`, and command publishing. The Go engine owns
> bot runtime status, due-check ticks, grids, paper and live orders/fills, risk
> and exit guards, backtest execution, and heartbeat. Live execution is gated by
> `TRADING_LIVE_ENABLED` (default false); testnet is the first rollout target,
> and production real-money live use needs an explicit operator yes.

## Components

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php-fpm` behind Nginx | Inertia/Vue dashboard and API endpoints |
| Queue worker | `php artisan horizon` | Alert email and other default-queue jobs only |
| Scheduler | `php artisan schedule:work` | Analytics and alert maintenance |
| Go engine | `engine` image | Bot ticks, orders, fills, risk, reconciliation, backtests |
| PostgreSQL | `postgres` | Shared durable state for Laravel and the engine |
| Redis | `redis-server` | `engine.commands`/`engine.events` streams, heartbeat, cache, Horizon |

Laravel is no longer the tick, order, or backtest worker. The PHP classes for
those paths were removed in phase 8; only the preview-only PHP strategies remain
for the create/edit ladder preview.

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

Run the Go engine separately from `engine/` when working outside Compose. It
needs the same PostgreSQL and Redis endpoints plus its own `ENGINE_*` settings;
see [engine/README.md](../engine/README.md).

`.env` holds database and exchange settings, so keep it owner-readable only
(mode `600`) when non-Docker processes run as your host user.

### Docker Compose

In Docker Compose these commands are wired into the entrypoint. Every service
waits for PostgreSQL and Redis, but only `app` has `RUN_MIGRATIONS=true`, so only
it runs `migrate --force` and `storage:link`. Horizon, the scheduler, and the
engine start after `app` is healthy and never migrate concurrently.

Under the bind-mounted Docker Compose setup, leave `.env` at mode `644`. PHP-FPM,
Horizon, and the scheduler run as Alpine `www-data` rather than your host user,
so a `600` file owned by your host user is unreadable inside the container.
Laravel then falls back to defaults. Do not run `chmod 600 .env` on a running
Docker deployment.

## Command and heartbeat flow

1. A dashboard action writes `desired_status` and publishes one envelope to
   `engine.commands` (`bot.start`, `bot.pause`, `bot.stop`, `order.cancel`,
   `kill_switch`). Backtests publish to `engine.backtests` so a long replay cannot
   delay control commands.
2. The engine consumes each stream independently, applies the command, and owns
   the runtime `status`, `last_error`, grids, orders, and fills. Duplicate command
   ids are ignored.
3. The engine writes `engine:heartbeat` every five seconds with a ten-second TTL.
   The payload includes the engine start time, the authoritative live-trading
   gate, and the public market-data mode/degraded state, in addition to the
   timestamp, revision, and running-bot count. Laravel reads it for the dashboard
   warning, the global engine status bar, bot-health stale detection, and
   stop/kill-switch emergency decisions. An expired heartbeat is a warning; it
   never pauses a bot or cancels an order automatically.
4. Laravel never writes runtime `status` or `last_error` after creation, and it
   does not write engine-owned activity types or `grids`/`grid_levels` rows.

## Bot controls

- **Start** writes `desired_status=running` and publishes `bot.start`. The live
  gate and exact `<bot name> on <exchange>` confirmation still run in Laravel
  before the command is published.
- **Pause** writes `desired_status=paused` and publishes `bot.pause`. Open orders
  are retained.
- **Stop** writes `desired_status=stopped` and publishes `bot.stop`. If the
  heartbeat is missing/expired or publishing fails, Laravel cancels open orders on
  that user's **live** bots through `CancelBotOrders` in the same request. Paper
  bots need only `desired_status` while the engine is down.
- **Single order cancel** publishes `order.cancel`; the dashboard row stays as-is
  until the engine confirms the cancellation.
- **Kill switch** writes `desired_status=paused` for every non-stopped bot and
  publishes `kill_switch`. If the heartbeat is missing/expired or publishing
  fails, Laravel cancels open live orders through `CancelBotOrders`. It does not
  wait for a tick.

A retried control action republishes when the runtime status disagrees with
`desired_status`; the UI shows the desired state while the engine catches up.

## Trading runtime status

The PHP tick and backtest engine has been removed and the Go trading runtime is
wired: control commands, the due-check scheduler, grid sync, paper fills,
strategy state, daily-loss and exit guards, and runtime status all run in the
engine. Live placement, cancellation, and reconciliation also run in the engine
when the gate is open. A closed gate pauses the live bot and records a risk
event; it never switches the bot to paper. Live stops/kill-switch use Laravel's
local `CancelBotOrders` safety net only when the heartbeat is unavailable or the
command publish fails; single live-order cancellation continues through
Laravel's local exchange path. Public prices and candle backfill currently support
Binance only; starting a paper bot on another exchange pauses it with a clear
error, while the MAX live adapter is fake-proven only.

There is no PHP-to-Go drain, adopt, or no-overlap runbook; the cutover is a
direct swap. Open orders written by the engine are durable in PostgreSQL and are
reconciled by the next tick after a restart.

A risk, protective-exit, daily-loss, kill-switch, reconciliation, live-gate,
strategy-stop, tick-failure, unsupported-exchange, or user pause never resumes
automatically; an operator must start it again. The one exception is startup recovery: once per
engine process, before the stale check, a non-archived paper Binance bot whose
recorded pause cause is `stale_tick` is resumed with a fresh first-tick grace.
Live bots, every safety pause, and legacy rows with no recorded cause stay
paused. After deploying this change, any bot paused before it has no cause and
needs one explicit operator start.

## Backtests

Laravel inserts `backtest_runs` / `backtest_sweeps` and publishes `backtest.run` or
`backtest.sweep`. The engine claims a pending run atomically, reads cached
`market_candles`, writes progress/status/metrics/equity/`backtest_fills`, and fails
a running run whose worker disappears or exceeds `BACKTEST_JOB_TIMEOUT + 60s`.
Pending runs are not failed for age alone because their commands remain durable in
the Redis stream; a 24-hour orphan guard catches rows that never received a
command. Laravel no longer schedules `backtests:fail-stale`.

## Cadences and latency

- Each bot evaluates at its stored `tick_interval_seconds`, default 60 seconds.
- Ticks read the cached public WebSocket last price. A missing cached price or
  one older than `max(15s, TRADING_TICK_INTERVAL / 2)` triggers one public REST
  snapshot; if that snapshot fails, the tick fails instead of using the old
  price. No private credential is used for market data.
- The dashboard polls every five seconds for persisted state and heartbeat; the
  poll never triggers a tick. The global engine status bar reuses
  `/health/engine` at most once every ten seconds per tab, including across
  Inertia navigation.
- Paper fills and daily-loss evaluation happen when a tick runs. A price that
  crosses and recrosses a paper limit between ticks is not guaranteed to fill.
- The kill switch does not wait for a tick; it publishes immediately and falls
  back to local live-order cancellation when the engine is unreachable.
- Analytics snapshots and alert pruning remain Laravel scheduled jobs.

## Queue operations

```bash
php artisan horizon:status
php artisan horizon:terminate        # graceful restart after deploys
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush              # only after reviewing failed jobs
php artisan queue:monitor redis:default
```

Horizon now serves alert email and other default-queue jobs, not ticks or
backtests. The `backtests` supervisor was removed in phase 7; do not add it back.

## Logs and observability

- Every started tick emits exactly one `bot tick completed` record. Its fields
  are `bot_id`, `correlation_id`, `outcome`, `duration_ms`,
  `orders_placed`, `orders_canceled`, `paper_fills`, `live_fills`,
  `grids_created`, `grids_matched`, `grids_replaced`, `levels_created`,
  `levels_matched`, and `levels_replaced`. A failed tick also carries
  `failure_type`.
- `outcome` is one of `completed`, `paused`, `failed`, or `panicked`. A tick
  that pauses a bot reports `paused`; a panic is logged before the panic is
  re-raised.
- `correlation_id` has the form `tick-<bot id>-<start UnixNano>`. Order,
  cancellation, fill, and reconciliation records produced by that tick include
  the same identifier, so one tick can be traced end to end.
- Engine logs include tick, order, fill, risk, reconciliation, and backtest
  events; Laravel redacts secrets in its own logs. Tick completion records never
  contain API keys, secrets, signatures, or the application key.
- To inspect tick completions, search the engine log stream:
  `docker compose -f docker-compose.prod.yml logs engine | grep 'bot tick completed'`.
  Docker's `json-file` driver stores the slog line in the record's `.log` field;
  for the raw file, use
  `LOG=$(docker inspect --format '{{.LogPath}}' "$(docker compose -f docker-compose.prod.yml ps -q engine)")`
  and `jq -r 'select(.log | contains("bot tick completed")) | .log' "$LOG"`.
- Activity log: user acknowledgements (`bot.started`, `bot.paused`, `bot.stopped`,
  `kill_switch.activated`, etc.) are written by Laravel; engine events such as
  `bot.stale`, `order.filled`, and `orders.synced` are written by the engine.
- `GET /health/engine` returns the heartbeat JSON with HTTP 200 when fresh and 503
  when missing/expired. It is marked `Cache-Control: no-store`; an older engine
  payload without the optional status fields is reported as unknown, not off.
- Horizon metrics cover the remaining default queue only.

## Deployment checklist

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan horizon:terminate
```

Restart Horizon, the scheduler, and the engine after every deploy. Confirm
`TRADING_LIVE_ENABLED=false` unless live trading has been explicitly approved.

## Release gate

Phase 8 lands on the `hybrid-engine` branch/tag. Do **not** merge to the
QNAP-deployable `origin/main` until the follow-up `add-qnap-ci-deploy` change adds
the engine to `docker-compose.prod.yml`, GHCR publishing, QNAP health/rollback,
and CI `go test`. The current production stack does not include the engine.
