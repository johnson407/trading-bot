# Tasks

## 0. Guardrails (every phase)

- [ ] 0.1 Do not edit `openspec/changes/add-qnap-ci-deploy/`, `docker-compose.prod.yml`, or the GHCR/CI workflow in this change; verify with a scoped `git status`.
- [ ] 0.2 Do not read or edit `.env` secrets, do not set `TRADING_LIVE_ENABLED=true`, and do not call authenticated live exchange endpoints; verify apply notes and CI stay paper/testnet-opt-in only.
- [ ] 0.3 After each phase, run `openspec validate adopt-hybrid-go-engine --strict` until the change is archived, and keep `docker compose exec` Pest (README testing env overrides) and PHPStan green for any Laravel work in that phase.

## 1. Go skeleton and local engine service

- [ ] 1.1 Create the Go module under `engine/` with a `main` that serves `GET /healthz` (liveness/readiness without calling an exchange) and writes a documentation-only heartbeat stub; verify `go test ./...`.
- [ ] 1.2 Add a multi-stage Dockerfile (non-root, distroless or alpine runtime) and an `engine` service to local `docker-compose.yml` with healthcheck, `depends_on` healthy Postgres and Redis, and the same `APP_KEY` / `DB_*` / `REDIS_*` / `TRADING_LIVE_ENABLED` (default false) environment pattern as `app`; verify `docker compose ps` shows `engine` healthy without placing orders.
- [ ] 1.3 Add a `go test` job or make target documented for CI later (no GHCR edit in this change); verify the command is recorded in the README or `engine/README.md`.

## 2. Command/event contract

- [ ] 2.1 Implement v1 envelope encode/decode, stream names `engine.commands` / `engine.events`, command and event types from `design.md`, and idempotency key `engine:cmd:{id}`; verify Go tests for unknown higher `v` (do not execute) and duplicate `id` (no second effect).
- [ ] 2.2 If Redis receipts are not enough for audit, add a Laravel-owned migration for a receipt table (engine never migrates); verify `php artisan migrate` on the test database only.
- [ ] 2.3 Document the contract in `docs/runtime.md` (or `docs/engine.md` linked from README); verify the types and kill-switch immediacy rules match `design.md`.

## 3. Market data

- [ ] 3.1 Implement Binance public WebSocket subscription for running-bot symbols, reconnect with backoff, gap or quiet-period detection, and REST ticker fallback; verify unit tests against a fake socket/server, with no live credentials.
- [ ] 3.2 Record degraded (REST fallback) state in logs/events without secrets; verify a test that a disconnect followed by a REST snapshot allows the feed to resume.

## 4. Golden fixtures, then paper engine and strategies

- [ ] 4.1 **Before deleting any PHP engine code**, capture golden fixtures from the current tick pipeline and the four strategies (and paper fill cases, including cross-and-recross-between-ticks); verify the fixture files are committed and the capture method is documented.
- [ ] 4.2 Port `StrategyInterface` behaviour, the four strategies, grid generation/sync, the tick loop (60s default, min 1s, one in-flight evaluation, stale `max(180, interval + health interval)`), and `qualifyingFills` paper parity into Go; verify Go tests pass against the fixtures from 4.1.
- [ ] 4.3 Keep PHP code in place until phase 8; verify no PHP strategy/tick files are deleted in this phase.

## 5. Risk and live gates in Go

- [ ] 5.1 Port `LiveModeGuard` (env gate; start requires sufficient free quote; no live-to-paper fallback), `RiskGuard`, `DailyLossMonitor`, the protective exit guard, `ConsecutiveFailureTracker`, and the exchange allowlist as blocking checks in the engine; verify Go tests for each pause/cancel/reject path.
- [ ] 5.2 Enforce `TRADING_LIVE_ENABLED` default false inside the engine before any private call; verify tests that a live bot is paused, not converted to paper, when the gate is off.
- [ ] 5.3 Implement in-memory Laravel-compatible `APP_KEY` decryption for exchange credentials, with redaction in logs; verify a unit test using a *stub* Laravel-encrypted value generated in the test, never a value from `.env`.

## 6. Backtest port

- [ ] 6.1 Capture PHP backtest golden results (deterministic replay, close-only fills, fees, risk and exit-guard replay, sweep grouping) before removing the PHP engine; verify fixtures are committed.
- [ ] 6.2 Port replay and sweeps to the engine; Laravel only inserts queued `backtest_runs` / `backtest_sweeps` and publishes `backtest.run` / `backtest.sweep`; the engine writes status, metrics, and `backtest_fills`; verify Go tests match 6.1 and Pest UI tests still enqueue and read results once wiring exists (or stay skipped until phase 7).

## 7. Laravel command/read wiring and schedule trim

- [ ] 7.1 Wire start, pause, stop, order cancel, kill-switch, and backtest submit to write desired Postgres status and XADD the matching command; verify Pest feature tests (Redis faked or a test stream) and that the kill-switch still does not wait for a tick.
- [ ] 7.2 Consume engine events as needed for alerts; expose engine heartbeat on the dashboard/health without changing the 5s poll; verify UI tests still pass and an expired heartbeat is visible.
- [ ] 7.3 Stop scheduling `bots:dispatch`, `bots:sync-orders`, `bots:sync-balances`, and `bots:reconcile` on the Laravel scheduler; leave alerts and analytics jobs; trim Horizon so tick/order/`RunBacktest` jobs are no longer the runtime path (PHP classes may still exist until phase 8); verify `SchedulerTest` and Horizon config match the trimmed set.

## 8. Remove the PHP engine

- [ ] 8.1 Delete the PHP tick pipeline, strategies, order placement/sync services, dispatch/reconcile/sync commands, `RunBotTick` / `PlaceOrder` / `SyncOrders` / `RunBacktest`, and `app/Domain/Backtest/Engine` after phases 4–7 are proven; verify no remaining runtime references and Pest/PHPStan are green.
- [ ] 8.2 Update README, `docs/runtime.md`, `docs/exchanges.md`, `docs/backtesting.md`, `docs/risk.md`, and `docs/security.md` for the hybrid layout, paper-fill semantics, credential rules, and a short note that prod Compose/CI/QNAP follow after `add-qnap-ci-deploy`; verify no doc still describes Laravel Horizon as the tick/order/backtest worker.

## 9. Integration proof

- [ ] 9.1 Run the documented Pest suite (`docker compose exec` with the README testing env overrides) and confirm the opt-in testnet test remains skipped.
- [ ] 9.2 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress` and `go test ./...` in `engine/`; verify no errors.
- [ ] 9.3 Confirm no live `.env` secret was printed, no live orders were placed, and `add-qnap-ci-deploy` is untouched; verify with `git diff --stat`.

## Workflow follow-up

- Archive this change after review and a successful apply-stage verification.
- A later change, after `add-qnap-ci-deploy`, must add the engine to `docker-compose.prod.yml`, GHCR, and the QNAP health/rollback flow.
