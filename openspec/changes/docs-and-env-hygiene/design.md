# Design

## Context

See `proposal.md` for motivation. The current state that shapes the approach:

- `.env` exists with mode `644`, owned by host uid/gid `1000`; `.env.example` is also `644`.
- `docker-compose.yml` bind-mounts the repository (`./:/var/www/html`) into `app`, `horizon`, and `scheduler`; Nginx mounts it read-only.
- Compose reads the host `.env` for `${VAR}` interpolation and injects only a subset into containers (`APP_ENV`, `APP_KEY`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `TRADING_LIVE_ENABLED`). Most `TRADING_*`, all `RISK_*`, and other keys are currently read by Laravel from the bind-mounted `.env`.
- `docker/php/Dockerfile` has no `USER` line and the entrypoint starts as root. `docker/php/entrypoint.sh` may create `.env` from the example and run `key:generate`, then `exec su-exec www-data` for Horizon/scheduler. PHP-FPM workers run as `www-data`, which on Alpine is typically uid/gid `82`, not host uid `1000`.

## Goals / Non-Goals

**Goals:**

- Make the README OpenSpec workflow accurate now that the MVP is archived and no change is active.
- Make `.env.example` complete for the trading/risk keys the application documents and reads, with safe defaults and no secrets.
- Give operators a clear `.env` permission step without breaking Docker paper trading.
- Define an apply-stage verification and fallback for the Docker uid mismatch.

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

Remove the claim that `openspec/changes/build-grid-trading-bot-mvp/` is the current change. State that there are no active changes and point historical MVP material at `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/`. Show commands that work in the current state or for a future change:

```bash
openspec list
openspec new change <name>
openspec validate <name> --strict
```

This avoids a path that fails and avoids implying an in-flight change.

### 3. Recommend `chmod 600 .env` with a verification-first Docker fallback

Document `chmod 600 .env` immediately after `cp .env.example .env` in both README setup paths and in `docs/runtime.md` first start. Add a short `docs/security.md` deployment note that `.env` must not be world-readable.

Do not chmod the running `.env` in this stage. During apply, verify the container can still boot and read non-injected config as `www-data`. If mode `600` (owned by uid `1000`) blocks PHP-FPM or drops `TRADING_*`/`RISK_*` values, add host-side env injection to Compose for `app`, `horizon`, and `scheduler`:

```yaml
env_file: .env
```

Compose reads that file as the host user, and Laravel's immutable env loading keeps already-injected process variables, so this preserves values even when `www-data` cannot read the file. Keep `600` and re-verify boot.

Alternatives considered and rejected:

- **Leave `644`**: simplest, but keeps database and exchange settings readable by every local user.
- **`640` plus a shared group**: fragile because Alpine `www-data` is typically gid `82` while the host group is not, and group IDs differ across hosts.
- **Run containers as uid `1000`**: avoids the read mismatch but risks storage/bootstrap-cache ownership problems and is a broader process-model change.
- **Document-only hardening for local Docker**: acceptable only if Johnson prefers it; it leaves the Docker bind-mount case world-readable and is less sound than the verified `env_file` fallback.

### 4. Leave archive task 9.2 untouched

The unchecked opt-in testnet task in the archived MVP is historical context. README already describes `composer test:testnet`; this change only keeps that documentation accurate and does not run, edit, or add tasks for 9.2.

## Risks / Trade-offs

- **Tightening `.env` without verification can break container boot or silently hide config** → verify `php artisan about` and `config:show trading` as `www-data`, recreate the services, and use Compose `env_file` if needed before considering the change complete.
- **`key:generate` may run as root in the entrypoint and change `.env` ownership** → record ownership/mode before and after (`stat`, no contents) and do not attempt an ownership redesign in this change unless it blocks the permission plan.
- **Adding `env_file` changes how env is supplied and can overlap existing `environment:` keys** → Compose `environment:` values still take precedence; add `env_file` only if verification requires it and document the behavior.
- **Example defaults could be mistaken for production settings** → keep paper/live-off/sandbox-off defaults and state that risk and trading values are operator-tunable.

## Open Questions

- Confirm the final approach with Johnson: recommend `chmod 600` plus Compose `env_file` only if verification requires it, or document-only hardening for local Docker. This does not change the example keys or README path fix.
