# Tasks

## 1. Config and environment defaults

- [x] 1.1 In `config/trading.php`, keep the existing env keys but change `trading.tick.interval_seconds` to default to `60` and `trading.tick.stale_after_seconds` to default to `180`; verify with a config assertion in `tests/Feature/StartupRecoveryTest.php` and `php artisan config:show trading` on a non-sensitive local config.
- [x] 1.2 Update `.env.example` to `TRADING_TICK_INTERVAL=60` and `TRADING_STALE_TICK_SECONDS=180`, and add comments distinguishing the one-second dispatcher due-check from the per-bot evaluation interval; verify the two keys and comments are present and no new env key was introduced.
- [x] 1.3 Update the `tests/Feature/StartupRecoveryTest.php` stale-default assertion from `60` to `180`; verify the test passes.

## 2. Bot creation defaults

- [x] 2.1 Replace the hardcoded `'tick_interval_seconds' => 1` in `app/Actions/CreateBot.php`, `database/factories/BotFactory.php`, and `database/seeders/DatabaseSeeder.php` with `max(1, (int) config('trading.tick.interval_seconds'))`; verify no remaining hardcoded interval default in those files.
- [x] 2.2 Add a `tests/Feature/BotCreationTest.php` case asserting a newly created bot stores 60 by default, and a case asserting a configured override (for example `config(['trading.tick.interval_seconds' => 5])`) is stored as 5; verify both pass.
- [x] 2.3 Verify the seeded demo bot picks up the configured default by running `tests/Feature/DemoWalkthroughTest.php`; confirm it still passes.

## 3. Existing bots migration

- [x] 3.1 Add a migration that changes the `bots.tick_interval_seconds` column default from `1` to `60` and updates existing rows where the value is still `1` to `60`; verify `php artisan migrate` succeeds and a fresh schema reports default `60`.
- [x] 3.2 Add `tests/Feature/BotTickIntervalMigrationTest.php` that exercises the migration against rows seeded with `1` and a deliberate non-default value, asserting the `1` row becomes `60` and the other value is unchanged; verify the test passes.
- [x] 3.3 Make the migration `down` restore the column default to `1` without rewriting data, and verify `php artisan migrate:rollback --step=1` followed by re-migrate succeeds on the test database.

## 4. Stale health threshold

- [x] 4.1 Change `BotHealthMonitor` to compute each running bot's cutoff from `max(trading.tick.stale_after_seconds, tick_interval_seconds + trading.health.check_interval_seconds)`, with the code fallback for the stale threshold set to `180`; verify the stale query still compares `COALESCE(last_successful_tick_at, last_tick_at, created_at)`.
- [x] 4.2 Add `tests/Feature/BotHealthTest.php` cases proving a 60-second bot that ticked within its interval plus the health-check interval is not paused, and that a future 300-second bot is not paused at the 180-second default but is paused past 330 seconds; verify the tests pass.
- [x] 4.3 Keep and re-run the existing stale-detection cases in `tests/Feature/BotHealthTest.php` and the slow-live-tick case in `tests/Feature/StartupRecoveryTest.php` (`it('allows slow live ticks before marking a bot stale')`); verify they pass against the effective threshold.

## 5. Documentation

- [x] 5.1 Update `README.md`, `docs/runtime.md`, and `docs/exchanges.md` so they separately describe the one-second `bots:dispatch` due-check, the per-bot evaluation interval (default 60 seconds), the unchanged health/order/balance schedules, and the unchanged five-second UI poll; verify no text still claims a one-second bot cadence.
- [x] 5.2 Document in the runtime docs that the capacity harness remains a ten-bot, one-second-interval stress target and is not the production default; verify the statement appears with the harness description.
- [x] 5.3 Document the operator step for existing deployments: change a pinned `TRADING_TICK_INTERVAL=1` or `TRADING_STALE_TICK_SECONDS=60` in the live `.env` and restart the scheduler/workers; verify the note names both keys and does not instruct automated `.env` edits.
- [x] 5.4 Document the accepted latency consequences: paper fills and daily-loss action happen only at tick time, and consecutive-failure pausing takes longer in wall-clock time while the threshold is unchanged; verify these limits are stated in the runtime docs.

## 6. Test guardrails

- [x] 6.1 Confirm `tests/Feature/SchedulerTest.php` still asserts `bots:dispatch` runs every second with a two-second overlap expiry and leave `routes/console.php` unchanged; verify the test passes.
- [x] 6.2 Confirm `tests/Feature/BotSchedulingTest.php` keeps explicit `tick_interval_seconds` values and does not rely on a factory default of `1`; verify the test passes.
- [x] 6.3 Confirm `tests/Feature/CapacityHarnessTest.php` keeps the ten-bot, one-second execution-budget stress target and does not retarget it to 60 seconds; verify the test passes.

## 7. Integration verification

- [x] 7.1 Run the documented test suite with `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync app composer test`; verify it is green and the opt-in testnet test remains skipped.
- [x] 7.2 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`; verify no errors.
- [x] 7.3 Confirm no live `.env`, Docker Compose, scheduler command, or testnet/exchange call was touched by the implementation; verify with a scoped `git diff --stat`.

## Workflow follow-up

- Archive this change after review and a successful apply-stage verification.
