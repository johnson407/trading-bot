# Design

## Context

See `proposal.md` for motivation. The runtime is one Go process with a one-second reconcile/due scheduler, Redis stream consumers, a heartbeat writer, an optional market-data feed and symbol syncer, and a stale-backtest sweeper. `reconcile` applies desired status, retries cancellations, and then pauses stale running bots. `runDue` spawns a goroutine per due bot and tracks only that a tick is running via `started`. `riskEvent` writes directly to `risk_events`. `main` starts every long-lived goroutine without a handle and only shuts down the HTTP server on a signal.

The Go suite runs through Docker (`go test ./...`); database tests are opt-in with `RUNTIME_TEST_DSN`. The repository has no Go toolchain on the host.

## Goals / Non-Goals

**Goals:**

- A restart after arbitrary downtime does not pause a bot before it has had one fair tick opportunity.
- A blocked position limit produces one informative event per bot per placement attempt, not one per order per tick.
- A stop signal stops new work and bounds the wait for in-flight work before exit.

**Non-Goals:**

- No change to the live-trading gate, order semantics, risk thresholds, or strategy behavior.
- No database migration or new persisted state.
- No general-purpose job queue or graceful-shutdown framework for Laravel services.

## Decisions

### Per-bot first-schedule grace instead of a global restart window

`Runtime` will keep a mutex-guarded `firstScheduled map[int64]time.Time`. The reconcile stale pass records the current time the first time it sees a running bot in this process and skips the pause until both `last_successful_tick_at` and that first-seen time are older than the bot's effective threshold. Entries are removed when a bot is no longer running or archived, so the map is bounded by active bots.

Alternatives: a single process-start timestamp is simpler but hides genuinely stale bots for the whole window and does not express the per-bot contract; running due ticks before the stale query races against the asynchronous tick goroutines. The chosen approach gives the first tick a chance while still pausing a bot whose ticks keep failing after the grace period. A `WithNow` option makes the grace boundary testable without sleeping.

### Aggregate and throttle position-limit events in the runtime

Each placement call counts orders skipped for the position limit and emits one `risk.position_limit` event after the loop with `orders_skipped`. A `Runtime`-level map keyed by bot and event type, guarded by a mutex, suppresses repeats for a five-minute cooldown; the event carries the count and the projected notional of the last skip. Other risk events and all pause/cancel events are unchanged.

Alternatives: emit only on a persisted state change would need a new column or settings key and a migration; emitting per tick without throttling still floods on a one-second interval. A fixed cooldown is bounded, has no schema change, and keeps the first breach visible. A shorter window would be noisier; five minutes keeps the log useful for alerting.

### Cancel-and-wait shutdown in `main`

`main` cancels the existing root context on `SIGINT`/`SIGTERM`, shuts the HTTP server down first so readiness fails, then waits on a `sync.WaitGroup` that wraps the scheduler, both consumers, heartbeat, market-data feed, symbol syncer, and sweeper. The wait is bounded at 20 seconds. `RunScheduler` tracks its tick goroutines and waits for them when its context ends, so the main wait also covers in-flight ticks. `docker-compose.yml` and `docker-compose.prod.yml` set `stop_grace_period: 25s`, which leaves margin above the 20-second drain.

Alternatives: calling `os.Exit` immediately loses the at-least-once safety window; waiting unbounded can exceed Docker's default 10-second grace and get `SIGKILL` anyway. Cancellation may abort an in-flight command; the consumer then leaves the entry in the pending list and reclaims it on the next start, and an aborted tick has not written `last_successful_tick_at`, so the restart grace covers it. The 20-second timeout is extracted into a small helper so it can be unit-tested.

### Tests follow the existing opt-in split

Throttle and drain-wait behavior get fast unit tests. Restart grace and event aggregation use the existing `RUNTIME_TEST_DSN` integration style because they read and write `bots` and `risk_events`. Grace boundaries are driven through a `WithNow` option rather than real sleeps.

## Risks / Trade-offs

- [Persistent position-limit blocks are visible only once per five minutes] → each event includes the skipped count and the projected notional, and pause/cancel events are untouched.
- [The first-schedule map grows with seen bots] → entries are pruned when a bot is not running or is archived.
- [Cancelling the root context can abort a command mid-effect] → durable effects commit before ack; unacked entries stay in the PEL and are reclaimed on restart. No ack happens before the durable effect.
- [Compose stop grace does not apply outside Compose] → the engine still drains; only the maximum window differs. The deployment docs note the expected value.
- [A very slow tick can outlive the 20-second drain] → the engine logs the timeout and exits; the tick writes are idempotent and reconciled on restart.

## Migration Plan

No database change. Deploy the engine image and the updated Compose files together. `stop_grace_period` is harmless for an older engine image. Rollback is the previous image; no data conversion is required.

## Open Questions

None.
