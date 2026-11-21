# Tasks

## 1. README OpenSpec workflow

- [ ] 1.1 Replace the current-change claim in README "OpenSpec workflow" with the archived location `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/` and state that there are currently no active changes. Verify `grep -n "changes/build-grid-trading-bot-mvp" README.md` returns no matches and the section names the archive path.
- [ ] 1.2 Replace the failing `openspec status --change build-grid-trading-bot-mvp` / `openspec validate build-grid-trading-bot-mvp --strict` example with commands that work now or for a future change (`openspec list`, `openspec new change <name>`, `openspec validate <name> --strict`). Verify `openspec list` succeeds, the README examples use valid command names, and the removed change name no longer appears in the section.

## 2. Complete `.env.example` with documented defaults

- [ ] 2.1 Add `TRADING_HEALTH_CHECK_SECONDS=30`, `TRADING_BALANCE_SYNC_SECONDS=60`, `TRADING_EXCHANGE_TIMEOUT_MS=15000`, `TRADING_EXCHANGE_RATE_LIMIT_MS=1000`, `TRADING_EXCHANGE_MAX_RETRIES=3`, `TRADING_EXCHANGE_RETRY_DELAY_MS=250`, and `TRADING_EXCHANGE_SANDBOX=false` to `.env.example`. Verify each key appears exactly once and its value matches the default in `config/trading.php`; compare with `grep -n "env('TRADING_" config/trading.php`.
- [ ] 2.2 Add `APP_PORT=8000` and the documented risk defaults `RISK_MAX_POSITION_NOTIONAL=1000`, `RISK_MAX_OPEN_ORDERS=20`, and `RISK_DAILY_LOSS_LIMIT=100`. Verify each key appears exactly once, the risk values match `config/risk.php` / `docs/risk.md`, and `APP_PORT` matches the `${APP_PORT:-8000}` mapping in `docker-compose.yml`.
- [ ] 2.3 Confirm `.env.example` gains no secrets or testnet credentials: `git diff -- .env.example` contains only the safe defaults above, with no `BINANCE_*`, `CCXT_TESTNET`, API-key, or secret values. Verify with `git diff --check -- .env.example` and a review of the added lines.

## 3. Document `.env` permissions

- [ ] 3.1 Add `chmod 600 .env` immediately after `cp .env.example .env` in both README setup paths (Docker quickstart and local setup) with a short note that `.env` should not be world-readable. Verify `grep -n "chmod 600 .env" README.md` shows both locations.
- [ ] 3.2 Add `chmod 600 .env` after `cp .env.example .env` in the `docs/runtime.md` "First start" block. Verify the command is present in that code block and the surrounding instructions remain accurate.
- [ ] 3.3 Add a short note to the `docs/security.md` "Transport and deployment" section that `.env` must not be world-readable (mode `600` on the host). Verify the note is present and does not claim `.env` contents are encrypted at rest.
- [ ] 3.4 Explain the Docker implication wherever the permission step is introduced: the repository is bind-mounted and PHP/Horizon run as Alpine `www-data`, so `600` owned by the host user may be unreadable inside the container. State that verification is required and that Compose host-side env injection is the fallback. Verify no documentation tells users to chmod the running `.env` without the verification step from group 4.

## 4. Apply-stage verification of permission tightening

- [ ] 4.1 Record metadata only before changing anything: `stat -c '%a %u:%g %n' .env .env.example`. Verify the output records mode/owner for both files and that no `.env` contents were printed or copied.
- [ ] 4.2 Establish a baseline while `.env` is still `644`: run `docker compose exec app php artisan about`, `docker compose exec -u www-data app php artisan about`, and a non-secret read such as `docker compose exec -u www-data app php artisan config:show trading`. Verify the commands succeed and show the expected trading configuration. Do not call any exchange or testnet endpoint.
- [ ] 4.3 Tighten the host file mode with `chmod 600 .env`; if `key:generate` previously changed ownership to root, fix ownership for the host user first. Verify with `stat -c '%a %u:%g' .env` that the mode is `600` and the host owner can read it, without printing contents.
- [ ] 4.4 Recreate only the application services (`docker compose up -d --force-recreate app horizon scheduler`), then verify `docker compose ps`, `docker compose logs --tail=50 app horizon scheduler`, `docker compose exec -u www-data app php artisan about`, `config:show trading`, and `horizon:status`. Verify boot is healthy and trading/risk values are still present for `www-data`; if not, continue to 4.5.
- [ ] 4.5 If `www-data` cannot read the `600` file or config values disappear, add `env_file: .env` to the `app`, `horizon`, and `scheduler` services in `docker-compose.yml` (keep existing `environment:` precedence), recreate those services, and repeat the 4.4 checks. Verify Laravel boots with the hardened file and the config values are present; do not revert to world-readable permissions or change Docker process users.
- [ ] 4.6 If the `env_file` fallback is used, document it in `README.md` and `docs/security.md` as the Docker-specific mechanism. Verify the docs mention host-side injection and remain free of secrets.

## 5. Sanity checks

- [ ] 5.1 Verify the working-tree diff is limited to the intended files: `git status --porcelain` and `git diff --stat` show `README.md`, `.env.example`, `docs/runtime.md`, `docs/security.md`, the change directory, and `docker-compose.yml` only if 4.5 was needed. Confirm no application code, tests, Dockerfile, or archived changes are modified.
- [ ] 5.2 Verify no testnet or exchange command was run and no stale archive content was edited: confirm `composer test:testnet` was not invoked, no Binance/CCXT/network command appears in the verification steps, and `git diff -- openspec/changes/archive` is empty.
- [ ] 5.3 Run `openspec validate docs-and-env-hygiene --strict` and `openspec status --change docs-and-env-hygiene`. Verify validation exits successfully and status reports proposal/design/tasks done with specs skipped.

## Workflow follow-up

- Archive this change after review and successful apply-stage verification.
- Leave archived task 9.2 (opt-in CCXT testnet suite) as historical context; do not edit the archive or add implementation work for it.
