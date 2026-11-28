# Proposal

## Why

Three production reliability defects in the Go engine can take healthy bots offline or hide real risk signals: a restart longer than the stale threshold pauses every running bot, a blocked position limit writes one critical event per order per tick, and `SIGTERM` exits without draining ticks, consumers, or the heartbeat. They share a trigger — restarts and high-load ticks — so fixing them together keeps the restart and shutdown paths coherent.

## What Changes

- Give each running bot a per-process grace period after engine start: the stale check pauses only when both the last successful tick and the time this process first scheduled the bot are older than the effective threshold. The first due tick therefore runs before staleness is judged.
- Aggregate blocked position-limit orders into one `risk.position_limit` event per bot per placement attempt, include the skipped-order count, and throttle repeats to at most once per cooldown window per bot and reason.
- Cancel a root context on `SIGINT`/`SIGTERM`, track the scheduler, in-flight tick workers, both Redis consumers, the heartbeat, the market-data feed and symbol syncer, and the stale-backtest sweeper in a `WaitGroup`, and wait up to 20 seconds for them before exit.
- Configure a 25-second `stop_grace_period` for the engine in both Compose files so the drain window completes before Docker sends `SIGKILL`.
- Add engine regression tests for restart grace, event dedupe/aggregation, and shutdown draining; update the engine docs.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: Restart recovery must not treat ordinary downtime as a stale tick, the stale check must respect a post-start grace period, and the engine must drain background work on shutdown.
- `risk-controls`: Risk events for repeated blocked orders must be deduplicated and aggregated instead of emitted per skipped order per tick.
- `deployment`: The production composition must allow the engine enough stop grace to drain.

## Impact

- Affected code: `engine/main.go`, `engine/internal/runtime/runtime.go`, `engine/internal/runtime/tick.go`, `engine/internal/runtime/live.go`, engine tests, `docker-compose.yml`, `docker-compose.prod.yml`, `engine/README.md`, `docs/engine.md`.
- No database migration, API, frontend, or live-trading gate change.
- Paper and live order paths both use the aggregated event and the shared shutdown path.
