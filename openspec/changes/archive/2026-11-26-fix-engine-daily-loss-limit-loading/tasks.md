# Tasks

## 1. Regression tests

- [x] 1.1 Add an integration test where an account daily-loss limit is set and a bot-scope row has a NULL daily-loss limit; verify a bot with a loss above the default but below the account limit stays running (the test must fail before the fix).
- [x] 1.2 Add an integration test where a bot-scope limit is lower than the account limit; verify the bot pauses at the bot-scope limit.
- [x] 1.3 Add an integration test with no `risk_limits` rows; verify a loss above the built-in default pauses the bot.
- [x] 1.4 Add an integration test that temporarily renames `risk_limits`, ticks a bot, and verifies it records a limit-read `last_error` and places no orders; verify the test fails before the fix, restores the table in `t.Cleanup`, and recovers a table left renamed by an interrupted run.
- [x] 1.5 Add an integration test that temporarily renames `day_start_realized_pnl`, ticks a bot, and verifies it records an account-baseline `last_error`, increments `consecutive_failures`, and places no orders; restore the column in `t.Cleanup` and recover a column left renamed by an interrupted run.

## 2. Daily-loss limit resolution

- [x] 2.1 Replace the daily-loss limit query with per-column resolution: bot-scope value, then account-scope value, then the built-in default; verify the NULL-override and missing-row tests pass.
- [x] 2.2 Return an error when the bot or account limit query fails, and when the account day-start/baseline query fails; treat `pgx.ErrNoRows` on the baseline query as a missing account row and use defaults; verify the fail-closed test passes.
- [x] 2.3 Propagate the `dailyLoss` error from `tickBot` into the existing `tickFailure` path; verify the fail-closed test persists `last_error` and leaves orders empty.
- [x] 2.4 Log a failed account-baseline update without failing the tick; verify the code compiles and the focused tests still pass.

## 3. Documentation

- [x] 3.1 Update the daily-loss notes in `engine/README.md` and `docs/engine.md` to state bot-then-account-then-default resolution and fail-closed behavior on an unreadable stored limit or account day-start baseline; verify the documented behavior matches the implemented tests.

## 4. Verification

- [x] 4.1 Run the focused runtime integration tests through the Docker Go image on the Compose network with `RUNTIME_TEST_DSN` pointing at `trading_bot_test`; verify all selected tests pass.
- [x] 4.2 Run `make -C engine test` and `make -C engine vet`; verify both exit successfully.
- [x] 4.3 Run `make -C engine fmt`; verify it prints no files.
