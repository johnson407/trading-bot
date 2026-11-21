# Grid Trading Bot

A Laravel 12 grid-trading engine for cryptocurrency markets with pluggable strategies,
paper trading by default, optional live Binance execution through CCXT, and an
authenticated Inertia + Vue dashboard.

> **Safety first:** paper mode is the default. Live mode requires an explicit
> environment gate, a stored API credential, an exact `<bot name> on <exchange>`
> confirmation, and a sufficient exchange balance. There is no silent live-to-paper fallback.

## Status

The MVP is implemented:

- Four strategies: Simple, Trailing, Martingale, and Dynamic grid.
- Durable grid, order, fill, inventory, P&L, strategy-state, and risk data.
- CCXT market data and live order execution, plus a public-data paper simulator.
- Queued one-second ticks with per-bot locking, startup reconciliation, health
  checks, and balance sync.
- Risk controls: position/open-order caps, per-bot and account-wide daily loss
  pauses, kill switch, and live-mode safeguards.
- Breeze authentication, authorization policies, bot creation/list/detail/edit,
  live controls, credentials, activity log, and a kill-switch confirmation page.

## Stack

| Layer | Choice |
| --- | --- |
| Application | PHP 8.4, Laravel 12 |
| Frontend | Inertia.js, Vue 3, Tailwind, Vite, Breeze auth |
| Exchange | CCXT, Binance by default |
| Queue and cache | Redis, Laravel Horizon |
| Database | PostgreSQL 16+ |
| Local runtime | Docker Compose (app, Nginx, PostgreSQL, Redis, Horizon, scheduler) |

## Requirements

### Docker quickstart

- Docker Engine with Compose v2

### Local setup

- PHP 8.4 with `bcmath`, `pdo_pgsql`, `redis`, `mbstring`, `xml`, `curl`, `intl`,
  `zip`, and `openssl`
- Composer 2
- Node.js 20+ and npm
- PostgreSQL 16+
- Redis 7+

## Docker quickstart

```bash
git clone <repository-url> trading-bot
cd trading-bot
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan db:seed --force
```

Open <http://localhost:8000>. The demo account is:

- Email: `demo@example.com`
- Password: `password`

If port 8000 is busy, set `APP_PORT=8080` in `.env` and use
<http://localhost:8080>. Useful commands:

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f horizon
docker compose logs -f scheduler
docker compose exec app php artisan about
docker compose exec app php artisan horizon:status
docker compose down
```

The entrypoint waits for PostgreSQL, generates `APP_KEY` when needed, and runs
`php artisan migrate --force`. The app, Nginx, PostgreSQL, Redis, Horizon, and
scheduler services each have health checks.

## Local setup without Docker

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set the database and Redis values in `.env`, then:

```bash
php artisan migrate --force
php artisan db:seed --force       # optional demo user/bot
npm install
npm run build
php artisan serve
```

Run the workers in separate terminals:

```bash
php artisan horizon               # queued bot ticks and order sync
php artisan schedule:work         # tick dispatch, health, balance sync
```

The dashboard is at <http://localhost:8000>. For frontend development, run
`npm run dev` alongside `php artisan serve`.

## Paper bot walkthrough

1. Sign in with the demo account, or register a new one.
2. Open **Bots → Create bot**.
3. Choose `BTC/USDT`, a strategy, an exchange from the allowlist (`binance` by
   default, configured with `TRADING_EXCHANGES`), and paper mode.
4. Set the center price, levels per side, spacing, and order quantity. Strategy
   specific fields appear for trailing, martingale, and dynamic grids.
5. Save, open the bot detail page, and press **Start**.
6. The scheduler dispatches a tick every second. The detail and list pages poll
   every five seconds for orders, fills, inventory, and P&L.
7. Press **Pause** to keep open orders, or **Stop** to cancel them.

Paper fills use public market data: a buy limit fills when the market price is at
or below the limit, and a sell limit fills when the market price is at or above it.
Fees are configurable per bot and default to zero.

## Strategies

| Key | Behavior |
| --- | --- |
| `simple-grid` | Static arithmetic or geometric levels around a center price |
| `trailing-grid` | Recenters when price moves beyond a trailing distance |
| `martingale-grid` | Increases order size after fills, capped by size and count |
| `dynamic-grid` | Recalculates spacing from recent volatility at a fixed interval |

See [docs/strategies.md](docs/strategies.md) for the contract, state model, and a
minimal custom strategy example.

## Live mode prerequisites

1. Create a Binance API key with **read** and **spot trade** permissions. Disable
   withdrawals and restrict the key to known IPs.
2. Add the credential under **Exchange credentials**. It is encrypted at rest,
   never returned in plaintext, and displayed masked.
3. Set `TRADING_LIVE_ENABLED=true` in the environment. It is `false` by default.
4. Create a live bot and fund the quote asset. The pre-start check requires free
   quote balance at least equal to the bot's capital allocation.
5. Start the bot by typing `<bot name> on <exchange>` in the confirmation field.
   The same phrase is required when creating a live bot.
6. Verify the connection on testnet first:

```bash
# .env
TRADING_EXCHANGE_SANDBOX=true
```

The opt-in CCXT integration suite can place and cancel a small testnet order:

```bash
CCXT_TESTNET=1 \
BINANCE_TESTNET_API_KEY=... \
BINANCE_TESTNET_API_SECRET=... \
composer test:testnet
```

See [docs/exchanges.md](docs/exchanges.md) for API permissions, testnet behavior,
retry/rate-limit handling, and failure semantics.

## Kill-switch drill

Use the kill switch to pause every bot and cancel all open orders immediately:

1. Open **Kill switch** from the dashboard.
2. Review the bot, active-bot, and open-order counts.
3. Type `KILL SWITCH` exactly and confirm.
4. Every non-stopped bot is paused immediately; cancellation runs per bot and
   partial failures are reported without leaving the account running.
5. Investigate the activity log and risk events, fix the cause, and start bots
   explicitly. Nothing resumes automatically.

The same behavior is available from the domain action
`App\Actions\ActivateKillSwitch`.

## Risk controls

- Per-bot and user-level limits for maximum position notional, maximum open orders,
  and daily loss.
- Daily loss uses a UTC calendar-day baseline and resets at midnight UTC.
- Breaches pause the bot and cancel open orders; they never auto-resume.
- Live starts require the configuration gate, a matching credential, the exact
  `<bot name> on <exchange>` confirmation, and a sufficient quote balance.
- Every breach is recorded as a risk event and an activity entry.

See [docs/risk.md](docs/risk.md) for formulas and limit precedence.

## Editing a bot

Idle and stopped bots can be edited from their detail page. The name, capital
allocation, fee rate, and strategy parameters are changeable. Symbol, exchange,
mode, and strategy are fixed for the lifetime of a bot; create a new bot to change
them. Running and paused bots must be stopped before saving edits.

## Testing and static analysis

Run the full suite, static analysis, and build the frontend inside the app container.

```bash
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  app composer test

docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec -u www-data app npm run build
```

Current results (Docker, PHP 8.4):

- Test suite: **360 passed, 1 skipped, 1716 assertions** (the skipped test is the opt-in Binance testnet suite).
- Static analysis: PHPStan level 5 with Larastan — **No errors**.
- Domain coverage: **91.8%** with PCOV. The Docker image includes PCOV; run the domain-scoped coverage config with the testing database:

```bash
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  app php -d pcov.enabled=1 -d pcov.directory=/var/www/html/app \
  vendor/bin/pest --configuration=phpunit.coverage.xml --coverage --min=90
```

The full suite uses the PostgreSQL `trading_bot_test` database. The opt-in testnet suite only runs when `CCXT_TESTNET=1` and Binance testnet credentials are provided.

## Runtime operations

```bash
php artisan bots:dispatch
php artisan bots:reconcile
php artisan bots:health
php artisan bots:sync-balances
php artisan horizon:status
php artisan horizon:terminate
php artisan queue:failed
```

See [docs/runtime.md](docs/runtime.md) for the tick pipeline, locks, recovery,
Horizon supervision, health checks, and the ten-bot capacity profile.

## Project layout

```text
app/
  Domain/          Bot, Grid, Order, Portfolio, Risk, Strategy, Exchange
  Strategies/      Simple, Trailing, Martingale, Dynamic
  Services/Exchange/  CCXT and paper clients
  Jobs/            RunBotTick, PlaceOrder, SyncOrders
  Actions/         Bot controls, credentials, kill switch
  Http/            Controllers, requests, resources
  Models/          Eloquent persistence
docs/              Strategies, exchanges, risk, runtime, dashboard, security
openspec/          Change proposals and capability specs
```

## Documentation

- [Strategies](docs/strategies.md)
- [Exchanges and live trading](docs/exchanges.md)
- [Risk controls](docs/risk.md)
- [Runtime and recovery](docs/runtime.md)
- [Dashboard flows](docs/dashboard.md)
- [Security checklist](docs/security.md)

## OpenSpec workflow

This repository uses OpenSpec for capability planning. The current change is
`openspec/changes/build-grid-trading-bot-mvp/`.

```bash
openspec status --change build-grid-trading-bot-mvp
openspec validate build-grid-trading-bot-mvp --strict
```

## Operational notes

- Keep `TRADING_LIVE_ENABLED=false` in local and staging environments.
- Run exactly one scheduler process and at least one Horizon supervisor.
- Do not horizontally scale `schedule:work`; per-bot locks prevent overlapping
  ticks, but the scheduler itself is a singleton.
- Back up PostgreSQL before live trading. Paper data is disposable.
- Before teardown of a live deployment, activate the kill switch and confirm all
  open orders are canceled.
