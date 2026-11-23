# Tasks

## 0. Guardrails (every phase)

- [x] 0.1 Do not edit `openspec/changes/add-qnap-ci-deploy/`, `docker-compose.prod.yml`, or the GHCR/CI workflow in this change; verify with a scoped `git status`.
- [x] 0.2 Do not read or edit `.env` secrets, do not set `TRADING_LIVE_ENABLED=true`, and do not call authenticated live exchange endpoints; verify apply notes and CI stay paper/testnet-opt-in only.
- [x] 0.3 After each phase, run `openspec validate adopt-hybrid-go-engine --strict` until the change is archived. For any Laravel work in that phase, keep `docker compose exec` Pest (README testing env overrides) and PHPStan green. For any Go work in that phase, run the `engine` `make test` target and keep it green. Phase work is not done without those proofs.

## 1. Go skeleton and local engine service

- [x] 1.1 Create the Go module under `engine/` with a `main` that serves `GET /healthz` (liveness/readiness without calling an exchange) and writes a documentation-only heartbeat stub; verify `make test` in `engine/` (or the equivalent documented target) passes.
- [x] 1.2 Add a multi-stage Dockerfile (non-root, distroless or alpine runtime) and an `engine` service to local `docker-compose.yml` with healthcheck, `depends_on` healthy Postgres and Redis, and the same `APP_KEY` / `DB_*` / `REDIS_*` / `TRADING_LIVE_ENABLED` (default false) environment pattern as `app`; verify `docker compose ps` shows `engine` healthy without placing orders.
- [x] 1.3 Add a required `make test` target in `engine/` (for example `go test ./...`) and document it in the README or `engine/README.md`. This target is a required gate for every later Go phase. Record that GHCR/CI will run it only in the later QNAP follow-up (no GHCR edit in this change); verify the command is documented and `make test` exits zero.

## 2. Command/event contract and status ownership

- [x] 2.1 Implement v1 envelope encode/decode, stream names `engine.commands` / `engine.events`, command and event types from `design.md`, and idempotency key `engine:cmd:{id}`; verify `make test` covers unknown higher `v` (do not execute) and duplicate `id` (no second effect).
- [x] 2.2 Implement at-least-once consumption: `XACK` only after durable Postgres effects and the idempotency key write, PEL auto-claim of idle pending entries on engine start, dead-letter to `engine.commands.dlq` after five failed attempts plus a failed event without secrets, and serial application of commands for the same `bot_id` in stream order; verify `make test` for reclaim-after-crash (completed id is acked with no second effect; incomplete id is retried), a poison message that is dead-lettered without blocking the group, and two commands for one bot applied in stream order.
- [x] 2.3 If Redis receipts are not enough for audit, add a Laravel-owned migration for a receipt table (engine never migrates); verify `php artisan migrate` on the test database only. **Skipped:** v1 audit uses Redis stream receipts plus `engine:cmd:{id}`; the decision is recorded in `docs/engine.md`.
- [x] 2.4 Add a Laravel-owned migration that introduces `bots.desired_status`, backfills it from the current `status`, and documents that Laravel writes only `desired_status` while the engine writes `status` and `last_error`; verify the migration on the test database only and that a rollback/forward test exists.
- [x] 2.5 Document the contract in `docs/runtime.md` (or `docs/engine.md` linked from README); verify the types, ACK/PEL/dead-letter/ordering rules, kill-switch immediacy (including the emergency path), and heartbeat-is-a-warning rules match `design.md`.

## 3. Market data

- [x] 3.1 Implement Binance public WebSocket subscription for running-bot symbols, reconnect with backoff, gap or quiet-period detection, and REST ticker fallback; verify `make test` against a fake socket/server, with no live credentials.
- [x] 3.2 Record degraded (REST fallback) state in logs/events without secrets; verify a test that a disconnect followed by a REST snapshot allows the feed to resume.

## 4. Golden fixtures, then paper engine and strategies

- [x] 4.1 **Before deleting any PHP engine code**, capture golden fixtures from the current tick pipeline and the four strategies (and paper fill cases, including cross-and-recross-between-ticks); verify the fixture files are committed and the capture method is documented.
- [ ] 4.2 Port the tick loop (60s default, min 1s, one in-flight evaluation, stale `max(180, interval + health interval)`) into Go; verify `make test` against the interval, overlap, and stale fixtures from 4.1.
- [ ] 4.3 Port grid generation and grid sync into Go; verify `make test` against the grid fixtures from 4.1.
- [ ] 4.4 Port `qualifyingFills` paper-fill parity into Go; verify `make test` against the paper-fill fixtures from 4.1, including cross-and-recross-between-ticks.
- [ ] 4.5 Port `SimpleGridStrategy` into Go; verify `make test` against its fixtures from 4.1.
- [ ] 4.6 Port `DynamicGridStrategy` into Go; verify `make test` against its fixtures from 4.1.
- [ ] 4.7 Port `MartingaleGridStrategy` into Go; verify `make test` against its fixtures from 4.1.
- [ ] 4.8 Port `TrailingGridStrategy` into Go; verify `make test` against its fixtures from 4.1.
- [ ] 4.9 Keep PHP code in place until phase 8; verify no PHP strategy/tick files are deleted in this phase.

## 5. Risk, live gates, and credentials in Go

- [ ] 5.1 Port `LiveModeGuard` (env gate; start requires sufficient free quote; no live-to-paper fallback) and enforce `TRADING_LIVE_ENABLED` default false inside the engine before any private call; verify `make test` that a live bot is paused, not converted to paper, when the gate is off, and that a start with insufficient quote is rejected.
- [ ] 5.2 Port `RiskGuard` (position and open-order caps, inventory reservation) as blocking checks; verify `make test` for each reject/defer path.
- [ ] 5.3 Port `DailyLossMonitor` (per-bot and account-wide pause and cancel, baseline in engine-owned settings keys); verify `make test` for pause, cancel, reset, and baseline-isolation paths.
- [ ] 5.4 Port the protective exit guard (pause and cancel, no market flatten, no auto-resume); verify `make test` for stop-loss, take-profit, range, precedence, and cancel-failure paths.
- [ ] 5.5 Port `ConsecutiveFailureTracker`; verify `make test` for the pause/cancel and failure-threshold paths.
- [ ] 5.6 Port the `TRADING_EXCHANGES` allowlist as a blocking check; verify `make test` that a non-allowed exchange cannot start or place.
- [ ] 5.7 Implement in-memory decryption compatible with Eloquent `encrypted` / `Crypt::encryptString` (AES-256-CBC, `base64:` `APP_KEY`, HMAC-SHA256 MAC, JSON `iv`/`value`/`mac`/`tag`, not PHP-serialized, `previous_keys` tried as Laravel would), with redaction in logs; verify `make test` using a *stub* value generated with `Crypt::encryptString` (or the model cast), never `Crypt::encrypt()`, and never a value from `.env`.

## 6. Backtest port

- [ ] 6.1 Capture PHP backtest golden results (deterministic replay, close-only fills, fees, risk and exit-guard replay, sweep grouping) before removing the PHP engine; verify fixtures are committed.
- [ ] 6.2 Port deterministic replay to the engine; Laravel only inserts queued `backtest_runs` and publishes `backtest.run`; the engine writes status, metrics, and `backtest_fills`; verify `make test` matches 6.1 replay fixtures.
- [ ] 6.3 Port sweeps to the engine; Laravel inserts queued `backtest_sweeps` and publishes `backtest.sweep`; verify `make test` matches 6.1 sweep fixtures.
- [ ] 6.4 Port stale-run failure to the engine (replacing `backtests:fail-stale`); verify `make test` that a run whose worker disappears or exceeds the timeout is marked failed with a concise message.
- [ ] 6.5 Verify Pest UI tests still enqueue and read results once wiring exists (or stay skipped until phase 7).

## 7. Laravel command/read wiring and schedule trim

- [ ] 7.1 Wire start to write `desired_status` only (not `status` or `last_error`) and `XADD` `bot.start`; verify a Pest feature test (Redis faked or a test stream).
- [ ] 7.2 Wire pause the same way with `bot.pause`; verify a Pest feature test.
- [ ] 7.3 Wire stop the same way with `bot.stop`, including the live emergency cancel via `CancelBotOrders` when the heartbeat is missing or expired or `XADD` fails; verify Pest tests for the happy path and for the emergency path.
- [ ] 7.4 Wire single-order cancel to publish `order.cancel`; verify a Pest feature test.
- [ ] 7.5 Wire kill-switch to write `desired_status` paused and `XADD` `kill_switch`, and to cancel open live orders via `CancelBotOrders` in the same action when the heartbeat is missing or expired or `XADD` fails; Laravel still does not write `status` or `last_error`; verify Pest tests that the kill-switch does not wait for a tick, and tests for both emergency conditions.
- [ ] 7.6 Wire backtest submit to insert the queued row and `XADD` `backtest.run` / `backtest.sweep`; verify a Pest feature test.
- [ ] 7.7 Consume engine events as needed for alerts; expose engine heartbeat on the dashboard/health as a warning only, without automatic pause, cancel, or kill-switch, and without changing the 5s poll; verify UI tests still pass, an expired heartbeat is visible, and a test that an expired heartbeat does not pause bots or cancel orders.
- [ ] 7.8 Stop scheduling `bots:dispatch`, `bots:sync-orders`, `bots:sync-balances`, `bots:reconcile`, `bots:health`, and `backtests:fail-stale` on the Laravel scheduler; leave alerts and analytics jobs; trim Horizon so tick/order/`RunBacktest` jobs are no longer the runtime path (PHP classes may still exist until phase 8); verify `SchedulerTest` and Horizon config match the trimmed set and that Laravel no longer writes stale-bot or stale-backtest rows.
- [ ] 7.9 Enforce activity-log type lists and `bots.settings` merge rules in Laravel (no overwrite of `mark_price`, `recent_prices`, `daily_loss_baseline`); verify Pest coverage that a bot update does not clobber engine settings keys and that Laravel does not insert engine-reserved activity types.

## 8. Cutover, remove the PHP engine, release gate

- [ ] 8.1 Write the operator cutover runbook in `docs/runtime.md` (or a linked doc) with the steps in `design.md` §9: pause or drain, empty Horizon, stop the trimmed schedules, start the engine and adopt open orders plus `desired_status = running` bots before new ticks, then delete PHP; verify the runbook exists and explicitly forbids running the PHP tick/order/backtest path and the Go engine against the same bots at the same time.
- [ ] 8.2 After phases 4–7 are proven and the cutover drain is complete, delete the PHP tick pipeline, strategies, order *placement* and sync services, dispatch/reconcile/sync/health/fail-stale commands, `RunBotTick` / `PlaceOrder` / `SyncOrders` / `RunBacktest`, and `app/Domain/Backtest/Engine`. **Keep** `CancelBotOrders` and the `CancelOrder` path it uses. Verify no remaining runtime references to the deleted PHP engine, that `CancelBotOrders` still exists, and that Pest/PHPStan are green.
- [ ] 8.3 Update README, `docs/runtime.md`, `docs/exchanges.md`, `docs/backtesting.md`, `docs/risk.md`, and `docs/security.md` for the hybrid layout, paper-fill semantics, credential rules (`encryptString` format), emergency cancel, heartbeat-as-warning, and the note that prod Compose/CI/QNAP follow after `add-qnap-ci-deploy`; verify no doc still describes Laravel Horizon as the tick/order/backtest worker, or an automatic pause on heartbeat expiry.
- [ ] 8.4 Do not merge this phase to the QNAP-deployable `origin/main` until a later change adds the engine to `docker-compose.prod.yml`, GHCR, QNAP health/rollback, and CI `go test`. Land phase 8 on a branch or tag (for example `hybrid-engine`) or record a release gate that `main` after phase 8 is not NAS-deployable; verify the gate or branch/tag is documented and `add-qnap-ci-deploy` is untouched.

## 9. Integration proof

- [ ] 9.1 Run the documented Pest suite (`docker compose exec` with the README testing env overrides) and confirm the opt-in testnet test remains skipped.
- [ ] 9.2 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress` and `make test` in `engine/`; verify no errors.
- [ ] 9.3 Confirm no live `.env` secret was printed, no live orders were placed, `CancelBotOrders` remains, and `add-qnap-ci-deploy` is untouched; verify with `git diff --stat`.

## Workflow follow-up

- Archive this change after review and a successful apply-stage verification.
- A later change, after `add-qnap-ci-deploy`, must add the engine to `docker-compose.prod.yml`, GHCR, CI `go test`, and the QNAP health/rollback flow. Until that follow-up lands, phase 8 must not be deployed to QNAP from `origin/main`.
