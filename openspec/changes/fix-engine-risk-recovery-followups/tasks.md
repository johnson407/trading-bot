# Tasks

## 1. Fail closed on account daily loss (#33)

- [x] 1.1 Add an `accountRealizedPNL` helper that returns wrapped query, scan, and `rows.Err()` failures, and make the account-loss loop propagate its read errors; verify with a focused internal test that an injected read failure makes `dailyLoss` return an error.
- [x] 1.2 Rework the UTC rollover to persist only after a successful account P&L read, bind the UTC day as a date string and compare `(day_start AT TIME ZONE 'UTC')::date`, treat zero affected rows as success, and return update errors so the next tick retries; verify new tests `TestRolloverFailureDoesNotPersistZeroBaseline` (a `BEFORE UPDATE` trigger on `risk_limits`) and `TestAccountBaselineRolloverIncludesArchivedRealizedPNL` (old `day_start`, archived bot with realized P&L) in `daily_loss_limit_integration_test.go`.
- [x] 1.3 Make `persistSettings` return its error, adopt the per-bot baseline only after a successful write, and propagate the failure from `dailyLoss`; verify `TestDailyLossFailsClosedWhenBaselineCannotBePersisted` uses a `BEFORE UPDATE` trigger on `bots` that raises only when `NEW.settings->'daily_loss_baseline'` changes, then asserts `last_error`, `consecutive_failures = 1`, and no orders. A blanket trigger on `bots.settings` is invalid because `rememberPrice` swallows that failure and exits the tick first.
- [x] 1.4 Add `TestDailyLossFailsClosedWhenAccountRealizedPNLCannotBeRead` to the new `daily_loss_internal_test.go` (package `runtime`, calls `dailyLoss` directly after renaming `bots.realized_pnl`, with a restore helper), and add the no-overwrite rollover test to `daily_loss_limit_integration_test.go`; verify with the opt-in runtime suite. The tick-level failure path is already covered by `TestDailyLossFailsClosedWhenRiskLimitsCannotBeRead`.
- [x] 1.5 Update `docs/risk.md` and `docs/engine.md` to state that account P&L and rollover failures fail closed and retry, and that archived bots remain in account exposure; verify the changed paragraphs match the spec scenarios and run `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync app php artisan test --filter=RiskDocsTest` (`docs/risk.md` must keep `midnight UTC`, the three `risk.defaults.*` keys, `TRADING_LIVE_ENABLED`, and `kill_switch_cancel_failed`).

## 2. Aggregate invalid-order risk events (#34)

- [x] 2.1 Add an `orderSkips` tracker with stable reasons and a total, then record skips instead of emitting in the paper placement path; verify a paper integration test with multiple invalid intents produces exactly one `risk.invalid_order` event with `orders_skipped` and `reasons`.
- [x] 2.2 Apply the same tracker to live placement and emit one aggregated event through a shared `recordInvalidOrderSkips` helper that uses the risk-event cooldown; verify a live placement test with the fake exchange produces one event.
- [x] 2.3 Rename the shared cooldown constant and add unit tests for aggregation, per-reason counts, cooldown suppression and expiry, and reservation release on write failure; verify with focused runtime tests.
- [x] 2.4 Update `docs/engine.md`, `docs/risk.md`, and `engine/README.md` to document one aggregated invalid-order event at most once per five-minute window; verify the documented event fields match the emitted context and run `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync app php artisan test --filter=RiskDocsTest`.

## 3. Keep pause provenance current and order recovery (#35)

- [x] 3.1 Refresh `pause_reason_recorded_at` in the same update whenever engine bookkeeping bumps `updated_at` for a paused bot: pause cancel-failure, reconcile cancel-failure, pending-flag clear, cancel-retry clear, and strategy-reset clear; verify `TestPauseWritesCauseAndRecordedTime` and a new test that each write leaves the cause eligible.
- [x] 3.2 Reorder `reconcile` so the one-shot auto-resume runs after the pending-flag cleanup loop and before the stale check, leave `autoResumePending` true when a cleanup apply fails, and add pending-flag predicates to the auto-resume update; add `TestAutoResumeAfterCancelRetryCleanup`, `TestAutoResumeAfterStrategyResetCleanup`, and `TestAutoResumeAfterReconcileCleanup` to `auto_resume_internal_test.go` (insert a stale-paused bot with one flag set, run `Reconcile`, assert status `running`, the flag cleared, and one `bot.auto_resumed` activity); verify with the focused runtime tests.
- [x] 3.3 Add tests that a still-pending cleanup flag blocks auto-resume, that a failed cleanup keeps `autoResumePending` true and the next pass resumes after the failure clears, and that `TestAutoResumeSkipsProvenanceInvalidatedByOlderEngine` still keeps the bot paused; also verify a bot whose provenance was bumped before this fix and has no pending flags stays paused after `Reconcile`.
- [x] 3.4 Update `docs/engine.md` and `docs/runtime.md` to describe provenance refresh during bookkeeping, the cleanup-before-auto-resume ordering, and the three pending-flag predicates in the eligibility list; verify the documented ordering and predicates match the new spec scenarios.

## 4. Integration verification

- [x] 4.1 Run `gofmt`, `go vet`, and the full engine suite through Docker with `RUNTIME_TEST_DSN` pointing at `trading_bot_test`; verify all tests pass and record the command used.
- [x] 4.2 Run `openspec validate fix-engine-risk-recovery-followups --strict`; verify it passes with no warnings.

## Verification record

- `docker run --rm --network trading-bot_default -v "$PWD:/src" -w /src/engine -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod -e RUNTIME_TEST_DSN='postgres://postgres:secret@trading-bot-postgres-1:5432/trading_bot_test?sslmode=disable' golang:1.24-alpine go test ./... -count=1` — all packages pass.
- `docker run --rm -v "$PWD:/src" -w /src/engine -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod golang:1.24-alpine sh -c 'gofmt -w internal/runtime/*.go && go vet ./...'` — pass.
- `docker run --rm --network trading-bot_default -u www-data -v /home/johnson/Repos/trading-bot:/var/www/html -v "$PWD/docs:/var/www/html/docs:ro" -w /var/www/html -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e DB_HOST=trading-bot-postgres-1 -e DB_USERNAME=postgres -e DB_PASSWORD=secret -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync --entrypoint php trading-bot-app:latest artisan test --filter=RiskDocsTest` — 1 passed, 9 assertions.
- `openspec validate fix-engine-risk-recovery-followups --strict` — valid.

## Workflow follow-up

- Request review of the engine and spec changes before merging, per `AGENTS.md`.
- Archive the change with the OpenSpec archive skill after review and verification.
