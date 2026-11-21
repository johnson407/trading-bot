# Tasks

## 1. README OpenSpec workflow

- [x] 1.1 Replace the current-change claim in README "OpenSpec workflow" with the archived location `openspec/changes/archive/2026-11-21-build-grid-trading-bot-mvp/` and describe archived changes rather than claiming there are no active changes. Verify `grep -n "changes/build-grid-trading-bot-mvp" README.md` returns no matches and the section names the archive path.
- [x] 1.2 Replace the failing `openspec status --change build-grid-trading-bot-mvp` / `openspec validate build-grid-trading-bot-mvp --strict` example with commands that work now or for a future change (`openspec list`, `openspec new change <name>`, `openspec validate <name> --strict`). Verify `openspec list` succeeds, the README examples use valid command names, and the removed change name no longer appears in the section.

## 2. Complete `.env.example` with documented defaults

- [x] 2.1 Add `TRADING_HEALTH_CHECK_SECONDS=30`, `TRADING_BALANCE_SYNC_SECONDS=60`, `TRADING_EXCHANGE_TIMEOUT_MS=15000`, `TRADING_EXCHANGE_RATE_LIMIT_MS=1000`, `TRADING_EXCHANGE_MAX_RETRIES=3`, `TRADING_EXCHANGE_RETRY_DELAY_MS=250`, and `TRADING_EXCHANGE_SANDBOX=false` to `.env.example`. Verify each key appears exactly once and its value matches the default in `config/trading.php`; compare with `grep -n "env('TRADING_" config/trading.php`.
- [x] 2.2 Add `APP_PORT=8000` and the documented risk defaults `RISK_MAX_POSITION_NOTIONAL=1000`, `RISK_MAX_OPEN_ORDERS=20`, and `RISK_DAILY_LOSS_LIMIT=100`. Verify each key appears exactly once, the risk values match `config/risk.php` / `docs/risk.md`, and `APP_PORT` matches the `${APP_PORT:-8000}` mapping in `docker-compose.yml`.
- [x] 2.3 Confirm `.env.example` gains no secrets or testnet credentials: `git diff -- .env.example` contains only the safe defaults above, with no `BINANCE_*`, `CCXT_TESTNET`, API-key, or secret values. Verify with `git diff --check -- .env.example` and a review of the added lines.

## 3. Document `.env` permissions

- [x] 3.1 Document `.env` permissions in both README setup paths: `chmod 600 .env` in the non-Docker local setup, and a note in the Docker quickstart that `.env` stays `644` because containers run as `www-data`. Verify `grep -n "chmod 600 .env" README.md` shows only the non-Docker path.
- [x] 3.2 Document the split permission policy in the `docs/runtime.md` "First start" block: `chmod 600 .env` for the non-Docker path and mode `644` for the Docker Compose bind-mount because containers read the file as `www-data`. Verify both cases are stated in or around that block.
- [x] 3.3 Document the split `.env` permission policy in the `docs/security.md` "Transport and deployment" section: mode `600` for non-Docker and mode `644` for the Docker Compose bind-mount. Verify the note does not claim `.env` contents are encrypted at rest.
- [x] 3.4 Explain the Docker implication wherever the permission step is introduced: the repository is bind-mounted and PHP/Horizon run as Alpine `www-data`, so a `600` file owned by the host user is unreadable inside the container. Verify no documentation tells users to chmod a running Docker `.env` without a host-side injection approach, and record that host-side injection was dropped for this change.

## 4. Apply-stage verification of permission tightening

- [x] 4.1 Record metadata only before changing anything: `stat -c '%a %u:%g %n' .env .env.example`. Verify the output records mode/owner for both files and that no `.env` contents were printed or copied.
- [x] 4.2 Establish a baseline while `.env` is still `644`: run `docker compose exec app php artisan about`, `docker compose exec -u www-data app php artisan about`, and a non-secret read such as `docker compose exec -u www-data app php artisan config:show trading`. Verify the commands succeed and show the expected trading configuration. Do not call any exchange or testnet endpoint.
- [x] 4.3 Tighten the host file mode with `chmod 600 .env`; if `key:generate` previously changed ownership to root, fix ownership for the host user first. Verify with `stat -c '%a %u:%g' .env` that the mode is `600` and the host owner can read it, without printing contents.
- [x] 4.4 Verify `www-data` read access without recreating services: after a temporary `chmod 600 .env`, run in-process checks as `www-data` (`php artisan about`, `config:show trading`, `horizon:status`). Verify the outcome is recorded and `.env` was restored to `644`; no `docker compose up --force-recreate`, restart, or recreate occurred.
- [x] 4.5 Dropped per Johnson: do not add Compose `env_file`; keep the Docker bind-mount `.env` at `644`.
- [x] 4.6 Dropped per Johnson: no Compose edits; documentation records mode `600` for non-Docker and `644` for the Docker Compose bind-mount.

## 5. Sanity checks

- [x] 5.1 Verify the working-tree diff is limited to the intended files: `git status --porcelain` and `git diff --stat` show `README.md`, `.env.example`, `docs/runtime.md`, `docs/security.md`, the change directory, and `docker-compose.yml` only if 4.5 was needed. Confirm no application code, tests, Dockerfile, or archived changes are modified.
- [x] 5.2 Verify no testnet or exchange command was run and no stale archive content was edited: confirm `composer test:testnet` was not invoked, no Binance/CCXT/network command appears in the verification steps, and `git diff -- openspec/changes/archive` is empty.
- [x] 5.3 Run `openspec validate docs-and-env-hygiene --strict` and `openspec status --change docs-and-env-hygiene`. Verify validation exits successfully and status reports proposal/design/tasks done with specs skipped.

## Workflow follow-up

- Archive this change after review and successful apply-stage verification.
- Leave archived task 9.2 (opt-in CCXT testnet suite) as historical context; do not edit the archive or add implementation work for it.

## Apply-stage outcome (2026-11-21, Asia/Taipei)

- 4.1: `.env` was mode `644`, owner `johnson:johnson` (uid 1000). Metadata only; contents were not read or printed.
- 4.2: Baseline as `www-data` on `app`, `horizon`, and `scheduler`: `.env` was readable; `php artisan about` succeeded (Application Name `Grid Trading Bot`, Debug ENABLED, Config NOT CACHED); trading/risk snapshots hashed identically; `horizon:status` reported running.
- 4.3: `chmod 600 .env` succeeded (still owner `johnson:johnson`).
- 4.4: No containers were recreated or restarted. After a temporary `chmod 600 .env`, fresh `www-data` processes could not read `.env`; `php artisan about` fell back to Application Name `Laravel` and Debug OFF on all three services, while trading/risk hashes stayed unchanged (those keys already matched config defaults) and `horizon:status` reported inactive. Mode was immediately restored to `644`.
- 4.5 / 4.6: Dropped per Johnson. Docker Compose keeps the bind-mounted `.env` at `644` so `www-data` can read it; no `env_file` was added and `docker-compose.yml` was not edited. Documentation now describes mode `600` for non-Docker local setups and `644` for the Docker Compose bind-mount.
- 5.1: Working tree is `README.md`, `.env.example`, `docs/runtime.md`, `docs/security.md`, and this change's `tasks.md` and `design.md` only.
- 5.2: Testnet/exchange commands were not run; archive directory was not edited. The Integration test remained skipped.
- 5.3: See validation output in the apply log. Default suite: 365 passed, 1 skipped, 1728 assertions. PHPStan: no errors.

## Post-review documentation fix (2026-11-21)

- Docker `.env` stays at `644`; non-Docker local setup keeps `chmod 600`. No `chmod`, Compose edit, service recreate, or restart was performed during this review fix.
- README, `docs/runtime.md`, and `docs/security.md` now state the split policy. `design.md` records Johnson's final decision, and 4.5/4.6 are marked dropped.
- `openspec validate docs-and-env-hygiene --strict` passed.
