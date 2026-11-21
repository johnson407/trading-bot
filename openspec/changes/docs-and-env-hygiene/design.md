# Design

## Context

See `proposal.md` for motivation. The current state that shapes the approach:

- `.env` exists with mode `644`, owned by host uid/gid `1000`; `.env.example` is also `644`.
- `docker-compose.yml` bind-mounts the repository (`./:/var/www/html`) into `app`, `horizon`, and `scheduler`; Nginx mounts it read-only.
- Compose reads the host `.env` for `${VAR}` interpolation and injects only a subset into containers (`APP_ENV`, `APP_KEY`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `TRADING_LIVE_ENABLED`). Most `TRADING_*`, all `RISK_*`, and other keys are currently read by Laravel from the bind-mounted `.env`.
- `docker/php/Dockerfile` has no `USER` line and the entrypoint starts as root. `docker/php/entrypoint.sh` may create `.env` from the example and run `key:generate`, then `exec su-exec www-data` for Horizon/scheduler. PHP-FPM workers run as `www-data`, which on Alpine is typically uid/gid `82`, not host uid `1000`.

## Goals / Non-Goals

**Goals:**

- Make the README OpenSpec workflow accurate now that the MVP is archived.
- Make `.env.example` complete for the trading/risk keys the application documents and reads, with safe defaults and no secrets.
- Give operators a clear `.env` permission policy without breaking Docker paper trading: `600` for non-Docker, `644` for the Docker Compose bind-mount.
- Record the apply-stage verification outcome instead of adding a Compose permission fallback.

**Non-Goals:**

- Running the exchange testnet suite, calling Binance/CCXT over the network, or changing live trading behavior.
- Changing `.env` permissions during the proposal stage.
- Editing archived changes, application code, tests, Docker images, or Docker process users.
- Mapping every Laravel environment key (mail, AWS, etc.); only keys documented for this application's trading/risk/runtime setup are in scope.

## Decisions

### 1. Complete `.env.example` with the documented defaults

Add these trading keys, matching `config/trading.php`:

| Key | Default |
| --- | --- |
| `TRADING_HEALTH_CHECK_SECONDS` | `30` |
| `TRADING_BALANCE_SYNC_SECONDS` | `60` |
| `TRADING_EXCHANGE_TIMEOUT_MS` | `15000` |
| `TRADING_EXCHANGE_RATE_LIMIT_MS` | `1000` |
| `TRADING_EXCHANGE_MAX_RETRIES` | `3` |
| `TRADING_EXCHANGE_RETRY_DELAY_MS` | `250` |
| `TRADING_EXCHANGE_SANDBOX` | `false` |

Add `APP_PORT=8000` (README Docker port mapping and `${APP_PORT:-8000}` in Compose), plus the small risk block documented in `docs/risk.md` and read by `config/risk.php`:

| Key | Default |
| --- | --- |
| `RISK_MAX_POSITION_NOTIONAL` | `1000` |
| `RISK_MAX_OPEN_ORDERS` | `20` |
| `RISK_DAILY_LOSS_LIMIT` | `100` |

Keep all defaults paper/sandbox-safe. Do not add Binance or testnet API-key placeholders; `CCXT_TESTNET` and `BINANCE_TESTNET_*` are opt-in runtime overrides for the skipped suite, not example configuration.

### 2. Fix the README OpenSpec section

Remove the claim that `openspec/changes/build-grid-trading-bot-mvp/` is the current change. Describe archived changes and point historical MVP material at `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/`. Show commands that work in the current state or for a future change:

```bash
openspec list
openspec new change <name>
openspec validate <name> --strict
```

This avoids a path that fails and avoids implying an in-flight change.

### 3. `600` for non-Docker, `644` under the Docker Compose bind-mount

Document `chmod 600 .env` immediately after `cp .env.example .env` only in the non-Docker README setup path and the non-Docker `docs/runtime.md` first start. Under the Docker Compose bind-mount, leave `.env` at `644` so Alpine `www-data` (PHP-FPM, Horizon, and the scheduler) can read it.

Johnson's final decision:

- **Non-Docker (local PHP):** `.env` stays mode `600`, because the process runs as the file owner.
- **Docker Compose bind-mount:** `.env` stays mode `644`. Containers do not run as the host user, so a `600` file owned by the host user is unreadable inside the container, and Laravel falls back for keys Compose does not inject.
- **Host-side Compose `env_file` injection:** considered and **dropped** for this change. Do not add `env_file`, do not chmod the running `.env`, and do not edit `docker-compose.yml`.

Apply-stage evidence: with `.env` temporarily at `600`, fresh `www-data` processes read Application Name as `Laravel` and Debug as OFF; restoring `644` fixed it without recreating services.

Alternatives considered and rejected:

- **`600` everywhere, including Docker:** blocks `www-data` on the bind mount and silently drops Application Name, debug, and other non-injected configuration.
- **`600` plus Compose `env_file: .env`:** would work in principle but was dropped by Johnson for this change; no Compose edit is part of the work.
- **`640` plus a shared group:** fragile because Alpine `www-data` is typically gid `82` while the host group is not, and group IDs differ across hosts.
- **Run containers as uid `1000`:** avoids the read mismatch but risks storage/bootstrap-cache ownership problems and is a broader process-model change.
- **Leave `644` everywhere:** rejected for non-Docker because it keeps database and exchange settings readable by every local user; accepted for the Docker bind-mount because of the uid mismatch.

### 4. Leave archive task 9.2 untouched

The unchecked opt-in testnet task in the archived MVP is historical context. README already describes `composer test:testnet`; this change only keeps that documentation accurate and does not run, edit, or add tasks for 9.2.

## Risks / Trade-offs

- **Tightening `.env` in Docker silently hides configuration** → the apply-stage check confirmed `www-data` cannot read a `600` file; Docker therefore keeps `644`, while non-Docker keeps `600`.
- **`key:generate` may run as root in the entrypoint and change `.env` ownership** → record ownership/mode before and after (`stat`, no contents) and do not attempt an ownership redesign in this change unless it blocks the permission plan.
- **Adding `env_file` would change how env is supplied and overlap existing `environment:` keys** → considered and dropped for this change; `docker-compose.yml` is not edited.
- **Example defaults could be mistaken for production settings** → keep paper/live-off/sandbox-off defaults and state that risk and trading values are operator-tunable.

## Open Questions

None. Johnson's decision is final: mode `600` for non-Docker, mode `644` for the Docker Compose bind-mount, and no `env_file` or Compose changes in this change.
