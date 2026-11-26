# AGENTS.md

Laravel 12 + Inertia/Vue grid-trading bot. Paper trading is the default; live trading is opt-in and safety-gated. Money handling, risk checks, and live-order paths are high-risk changes.

## Worktrees

- Do feature and change work in a dedicated git worktree, not directly on `main`. Keep the OpenSpec change, code, tests, and docs together in that worktree, and merge only after review and verification.

## Run and verify

Local development uses `docker-compose.yml`, and the commands below run PHP/Node inside the `app` service. Start the stack once:

```bash
docker compose up -d --build
docker compose exec app php artisan db:seed --force   # demo@example.com / password
```

Web: <http://localhost:8000>. If port 8000 is taken, set `APP_PORT` in `.env`.

```bash
# full PHP suite (Pest)
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  app composer test

# focused test (keep the same -e overrides)
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  app php artisan test --filter=BacktestDashboardTest

# static analysis (level 5, app/ only)
docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# PHP formatting (Pint default preset; no pint.json)
docker compose exec -u www-data app vendor/bin/pint --dirty

# frontend build and JS unit tests
docker compose exec -u www-data app npm run build
docker compose exec -u www-data app npm run test:js
```

`composer test` clears config then runs the whole suite. All three suites boot Laravel — `tests/Unit` is not a plain PHPUnit suite. Tests that touch persistence call `uses(RefreshDatabase::class)`.

### Test environment gotchas

- The suite uses PostgreSQL, not SQLite. It expects a pre-created `trading_bot_test` database; Compose's `POSTGRES_DB` only creates the dev database:
  `docker compose exec postgres createdb -U trading_bot trading_bot_test`
- PHPUnit does **not** override environment variables already set in the container. Always pass `-e DB_DATABASE=trading_bot_test` (plus the other overrides above); otherwise tests can run against the dev database.
- `tests/Integration/CcxtTestnetTest.php` is opt-in and skipped unless `CCXT_TESTNET=1` and Binance testnet credentials are supplied. Do not enable it in normal runs.
- Docs are executable test fixtures: `docs/dashboard.md`, `docs/exchanges.md`, and `docs/risk.md` are asserted by tests (`DashboardDocsTest`, `ExchangeDocsTest`, `RiskDocsTest`). Config keys/defaults, route names, and feature-test names mentioned there must stay in sync.

## Architecture

Single Laravel application, no monorepo.

- `app/Domain/` — Laravel-side trading domain: Bot, Order, Portfolio, Risk, Strategy preview, Exchange, Backtest value objects, Alerts, Analytics, Exit.
- `app/Strategies/` — the four built-in strategies, retained only for the create/edit ladder preview. Runtime strategies live in `engine/internal/strategy`; changes must be mirrored.
- `app/Services/Exchange/` — CCXT and paper exchange adapters used by previews and emergency cancellation.
- `app/Jobs/` — `SendAlertEmail` only; the PHP tick/order/fill and backtest pipelines were removed in phase 8.
- `app/Services/Engine/` — Redis command publisher and heartbeat reader.
- `app/Http/` + `resources/js/Pages/` — Inertia controllers and Vue pages.
- `app/Models/`, `database/migrations/` — Eloquent persistence.

Scheduling lives in `routes/console.php` (there is no `app/Console/Kernel.php`). After phase 7, Laravel schedules only `analytics:capture-equity`, `alerts:prune`, and `analytics:prune-equity`; `bots:dispatch` and the other PHP tick/backtest commands are no longer the runtime path. Run exactly one `schedule:work`; Horizon remains for alerts and other default-queue jobs.

To add a strategy for preview, implement `App\Domain\Strategy\Contracts\StrategyInterface` and register it in `AppServiceProvider::boot()`. A runtime strategy must also be added to the Go strategy factory and fixture tests. The `key()` is persisted in `strategy_configs.strategy_key`, so renaming it requires a data migration.

## Money and precision

- Use `App\Domain\Support\Decimal` (bcmath strings). Floats are rejected on purpose and must never enter monetary or quantity calculations.
- Eloquent `decimal:16` casts return strings with trailing zeros, e.g. `"1000.0000000000000000"`. Tests assert those strings.
- On the frontend, format monetary values with `resources/js/Support/money.js` (`formatMoney` / `formatSignedMoney`); do not use `toFixed` or raw bindings. Keep quantities, fee rates, and percentages unformatted.

## Safety constraints

- Never set `TRADING_LIVE_ENABLED=true` in local/test work, and never bypass the live gate, credential check, balance check, or the exact `<bot name> on <exchange>` confirmation.
- Risk breaches and the kill switch pause bots and cancel orders; nothing resumes automatically.
- Keep the queue/Horizon path intact: bot ticks and backtests are queued, not executed inline in HTTP requests.

## Environment and Docker

- Under local Docker Compose, leave `.env` at mode `644`. PHP-FPM, Horizon, and the scheduler run as Alpine `www-data`, so a host-owned `600` file is unreadable and Laravel silently falls back to defaults. Use `600` only for non-Docker local runs.
- The entrypoint waits for PostgreSQL and runs `migrate --force` only when `RUN_MIGRATIONS=true`. The local/production `app` service sets it true; Horizon and the scheduler set it false. Migrations run forward only.
- Backtests use a dedicated `backtests` queue and have bounded caps in `config/backtest.php` (`max_fills`, `sweep_max_runs`, `job_timeout`); do not remove those guards.

## CI and deployment

- `.github/workflows/ci.yml` runs the PHP suite and frontend build on pull requests and pushes. Pull requests also build the production image without pushing it.
- Pushes to `main` publish SHA-tagged and `latest` application/Nginx images to GHCR. A QNAP NAS pulls them on a schedule; see `docs/deployment.md`.
- Local development uses `docker-compose.yml` (bind mount). Production uses `docker-compose.prod.yml` (prebuilt images, no bind mount) and must keep `RUN_MIGRATIONS=false` on Horizon and the scheduler.

## OpenSpec workflow

Planning artifacts live under `openspec/`. Use the OpenSpec skills for proposing, applying, syncing, and archiving changes. CLI equivalents:

```bash
openspec list
openspec new change <name>
openspec validate <name> --strict
```
