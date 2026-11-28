# Tasks

## 1. Restart grace for stale checks (issue #12)

- [x] 1.1 Add opt-in runtime integration tests for restart grace: a running bot with an old `last_successful_tick_at` stays running on the first reconcile pass, and is paused only after the per-bot grace threshold elapses; verify both tests fail against the current runtime with `RUNTIME_TEST_DSN` set.
- [x] 1.2 Add a `WithNow` runtime option and a mutex-guarded first-scheduled map; require both the last successful tick and the first-scheduled time to exceed the effective threshold before pausing, and prune entries for bots that are no longer running; verify the new restart-grace tests pass.
- [x] 1.3 Document the restart grace behavior in `engine/README.md` and `docs/engine.md`; verify the documented threshold and first-tick behavior match the tests.

## 2. Position-limit event aggregation (issue #13)

- [x] 2.1 Add a runtime unit test for the bot-plus-reason cooldown and an opt-in integration test where one placement attempt blocks two orders and writes one `risk.position_limit` event with `orders_skipped`; verify the integration test fails against the current per-order emission.
- [x] 2.2 Count position-limit skips in both paper and live placement, emit one aggregated event per placement attempt, and suppress repeats inside the five-minute cooldown; verify the aggregation and cooldown tests pass.
- [x] 2.3 Document the aggregated, throttled position-limit event in `engine/README.md` and `docs/engine.md`; verify the docs name the event, the count, and the cooldown.

## 3. Graceful engine shutdown (issue #14)

- [x] 3.1 Extract a bounded drain-wait helper in `engine/main.go` and add package tests for the completed-wait and timeout paths; verify the helper tests pass.
- [x] 3.2 Track the scheduler, both consumers, heartbeat, market-data feed, symbol syncer, and stale-backtest sweeper in a `WaitGroup`; track tick goroutines in `RunScheduler`; cancel the root context on a signal, shut down HTTP, and wait up to 20 seconds; verify the package tests and `go test ./...` pass.
- [x] 3.3 Add `stop_grace_period: 25s` to the engine service in both Compose files and document the stop behavior in `engine/README.md`; verify `docker compose config` accepts both files and the docs state the drain timeout.

## 4. Integration verification

- [x] 4.1 Run the full Go suite with `docker run ... go test ./...` and verify it passes.
- [x] 4.2 Run `go vet ./...` and `gofmt -l .` through the pinned Go image and verify vet passes and formatting reports no files.
- [x] 4.3 Run the focused runtime integration tests against `trading_bot_test` with `RUNTIME_TEST_DSN` and verify the restart-grace, event-aggregation, and existing runtime tests pass.
- [x] 4.4 Run `openspec validate fix-engine-restart-risk-shutdown --strict` and verify it passes.

## Workflow follow-up

- Run the code-review workflow after apply and address findings.
- Open one pull request that closes issues #12, #13, and #14.
- Archive the change after merge and review.
