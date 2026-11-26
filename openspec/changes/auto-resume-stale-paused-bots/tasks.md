# Tasks

## 1. Pause provenance

- [ ] 1.1 Add nullable engine-owned `pause_reason` and `pause_reason_recorded_at` columns to `bots` with a forward migration, a check constraint for the fixed cause set, and no backfill; verify the migration applies and an existing paused row keeps a null cause.
- [ ] 1.2 Define typed pause-cause constants and write the matching cause and recorded time in every engine pause path (`stale_tick`, risk, protective exit, daily loss, kill-switch, reconciliation conflict, tick failure, live gate, strategy stop, unsupported exchange, user); verify a table-driven runtime test enumerates every pause call site and asserts the stored cause.
- [ ] 1.3 Clear both fields on user start, stop, and successful auto-resume; verify tests assert they are null after each transition and never changed by Laravel. Add a rollback test that simulates an older engine changing `updated_at` without touching provenance and asserts the cause is treated as stale.
- [ ] 1.4 Make the pause helper return or log write failures instead of discarding them; verify a failed pause write is visible and does not silently leave the bot running.

## 2. Startup recovery

- [ ] 2.1 Add a once-per-process startup query for non-deleted, paper, Binance, `paused`, `desired_status = running`, current `stale_tick` cause, and no pending kill-switch; add the conditional update that rechecks every predicate under the existing bot lock; verify unit tests cover eligible, live, unsupported exchange, wrong desired status, wrong cause, stale timestamp, archived, pending kill-switch, and concurrent-change cases.
- [ ] 2.2 Run recovery at the first reconcile before desired-status drift and stale checks; clear the pending flag only after the query and candidate updates complete without a transient error; verify an integration test resumes a stale-paused paper bot, grants first-tick grace, and retries after an injected per-bot update failure.
- [ ] 2.3 Add a bounded startup schema probe for both new columns before the pool consumers, scheduler, or market feed start; verify a test starts against an un-migrated database and confirms the engine exits before any worker starts.
- [ ] 2.4 Insert `bot.auto_resumed`, emit a structured log record with bot ID and cause, and add the type to the engine-owned list in `ActivityRecorder`; verify tests assert the activity, the type list stays valid, and an activity insert failure is logged without rolling back the resume.

## 3. Safety matrix

- [ ] 3.1 Add integration coverage proving live, unsupported-exchange, risk, protective-exit, daily-loss, kill-switch, tick-failure, reconciliation, live-gate, strategy-stop, user, unknown, legacy-null, and stale-timestamp cases stay paused; verify `go test ./internal/runtime` with `RUNTIME_TEST_DSN` passes those cases.
- [ ] 3.2 Add coverage for user-paused, user-stopped, archived (`deleted_at`), and already-resumed-this-process cases; verify a resumed bot that becomes stale again stays paused until the next process start, and a restored archived bot stays stopped.
- [ ] 3.3 Update `docs/runtime.md`, `docs/engine.md`, `docs/risk.md`, `AGENTS.md`, and the status-ownership table with the pause causes and the paper-only stale resume rule; verify the risk documentation fixture test and the documented wording match the behavior.

## 4. Integration verification

- [ ] 4.1 Run `cd engine && make test` with `RUNTIME_TEST_DSN` pointing at the migrated test database, plus the focused and full PHP suites including the risk docs fixture; verify all pass.
- [ ] 4.2 Run `openspec validate auto-resume-stale-paused-bots --strict`; verify it passes.
