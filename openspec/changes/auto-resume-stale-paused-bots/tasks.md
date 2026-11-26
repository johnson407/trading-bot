# Tasks

## 1. Pause provenance

- [ ] 1.1 Add a nullable engine-owned `pause_reason` column to `bots` with a forward migration and no backfill; verify the migration applies and an existing paused row keeps a null cause.
- [ ] 1.2 Define the pause-cause values and write the matching cause in every engine pause path (`stale_tick`, risk, protective exit, daily loss, kill-switch, reconciliation conflict, tick failure, live gate, strategy stop, unsupported exchange); verify runtime tests assert the stored cause for representative paths.
- [ ] 1.3 Clear `pause_reason` on user start, stop, and successful auto-resume; verify tests assert it is null after each transition and never changed by Laravel.

## 2. Startup recovery

- [ ] 2.1 Add a once-per-process startup query for non-deleted, `paused`, `desired_status = running`, `pause_reason = stale_tick` bots with no pending kill-switch request, and a conditional update that rechecks the state under the existing bot lock; verify unit tests cover eligible, wrong desired status, wrong cause, archived, pending kill-switch, and concurrent-change cases.
- [ ] 2.2 Run recovery at the first reconcile before desired-status drift and stale checks, leaving the pending flag set on a query error; verify an integration test resumes a stale-paused bot and grants first-tick grace.
- [ ] 2.3 Insert `bot.auto_resumed`, emit a structured log record with bot ID and cause, and add the type to the engine-owned list in `ActivityRecorder`; verify tests assert the activity and that the type list stays valid.
- [ ] 2.4 Make the production engine wait for the application service to be healthy so migrations run before the new column is queried; verify `ProductionComposeTest` or the Compose config check asserts the dependency.

## 3. Safety matrix

- [ ] 3.1 Add integration coverage proving risk, protective-exit, daily-loss, kill-switch, tick-failure, reconciliation, live-gate, strategy-stop, and unknown/legacy causes stay paused; verify `go test ./internal/runtime` with `RUNTIME_TEST_DSN` passes those cases.
- [ ] 3.2 Add coverage for user-paused, user-stopped, archived (`deleted_at`), and already-resumed-this-process cases; verify a resumed bot that becomes stale again stays paused until the next process start.
- [ ] 3.3 Update `docs/runtime.md`, `docs/engine.md`, and `docs/risk.md` with the pause causes and the stale-only resume rule; verify the risk documentation fixture test and the documented wording match the behavior.

## 4. Integration verification

- [ ] 4.1 Run `cd engine && make test` with `RUNTIME_TEST_DSN` pointing at the migrated test database, plus the focused and full PHP suites including the risk docs fixture; verify all pass.
