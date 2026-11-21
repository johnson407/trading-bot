# Proposal

## Why

The README's OpenSpec workflow points at `openspec/changes/build-grid-trading-bot-mvp/`, which no longer exists (the MVP was archived), so its commands fail and imply an active change that is not there. `.env.example` also omits several configuration keys that `config/trading.php`, `config/risk.php`, and the Docker setup already read, and the real `.env` is world-readable (`644`), exposing exchange and database settings to local users.

## What Changes

- Fix the README "OpenSpec workflow" section: point historical MVP material at `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/`, state that there are currently no active changes, and show commands that work without an active change or against a future change (`openspec list`, `openspec new change <name>`, `openspec validate <name> --strict`).
- Complete `.env.example` with the documented trading keys that are currently missing, using the same safe, non-secret defaults as `config/trading.php`: `TRADING_HEALTH_CHECK_SECONDS=30`, `TRADING_BALANCE_SYNC_SECONDS=60`, `TRADING_EXCHANGE_TIMEOUT_MS=15000`, `TRADING_EXCHANGE_RATE_LIMIT_MS=1000`, `TRADING_EXCHANGE_MAX_RETRIES=3`, `TRADING_EXCHANGE_RETRY_DELAY_MS=250`, and `TRADING_EXCHANGE_SANDBOX=false`.
- Add documented defaults that are missing from the example: `APP_PORT=8000` (used by the Compose port mapping) and a small related block for `RISK_MAX_POSITION_NOTIONAL=1000`, `RISK_MAX_OPEN_ORDERS=20`, and `RISK_DAILY_LOSS_LIMIT=100` from `config/risk.php` / `docs/risk.md`.
- Document `chmod 600 .env` immediately after `cp .env.example .env` in README Docker and local setup, and in `docs/runtime.md` first start. Add a short note to `docs/security.md` that `.env` must not be world-readable.
- Require apply-stage verification before tightening the existing `.env` mode, because Compose bind-mounts the repository and Laravel runs as Alpine `www-data`, not the host uid. If `600` breaks boot or drops config, prefer host-side Compose env injection (for example `env_file: .env`) over leaving the file world-readable or relying on fragile uid/gid mapping.
- Do not add secret placeholders or change live trading behavior. `CCXT_TESTNET` / `BINANCE_TESTNET_*` remain opt-in runtime overrides for the skipped test suite and stay empty/uncommitted.

No breaking changes.

## Capabilities

### New Capabilities

None. This is documentation and template hygiene with no spec-level behavior change, so `.openspec.yaml` sets `skip_specs: true`. No capability is introduced or modified.

### Modified Capabilities

None. Existing capabilities describe runtime behavior, which this change does not alter.

## Impact

- `README.md`: correct the archived OpenSpec path and workflow commands, add the first-start permission step, and keep the existing testnet documentation accurate.
- `.env.example`: add the missing documented keys with safe defaults; no secrets.
- `docs/runtime.md`: add `chmod 600 .env` to the first-start sequence.
- `docs/security.md`: note the host-side `.env` permission requirement in transport/deployment guidance.
- `docker-compose.yml`: only if apply-stage verification shows the container cannot read the hardened `.env`; in that case add host-side env injection (`env_file: .env`) to `app`, `horizon`, and `scheduler`. No application code, tests, Docker image, or process-user changes are planned.

**Decision to confirm:** Johnson may choose the recommended `chmod 600` plus, if needed, Compose `env_file` approach, or document-only hardening for local Docker. The existing running `.env` is not changed during proposal. Verification must confirm `php artisan about` and a safe Horizon/scheduler boot as `www-data` before the mode is tightened.

**Out of scope:** the unchecked archive task 9.2 in `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/tasks.md` (opt-in CCXT testnet suite) remains historical context only. This change will not run testnet, edit archives, or add implementation tasks for 9.2.
