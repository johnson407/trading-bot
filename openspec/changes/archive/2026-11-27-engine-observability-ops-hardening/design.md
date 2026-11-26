# Design

## Context

See `proposal.md` for motivation and the `bot-runtime` and `deployment` deltas for the contract. The engine is one Go process using `log/slog` to stdout. `Runtime.tickBot` has many early returns and currently emits no success record; `runDue` logs only scheduler failures and panics. Production runs seven Compose services behind Docker's `json-file` driver, with only Nginx publishing a host port. The plan endpoint already authenticates with a bearer token when `ENGINE_INTERNAL_TOKEN` is set, but the production composition does not require it.

## Goals / Non-Goals

**Goals:**

- Exactly one completion record per started tick, including successful ticks, with enough fields to measure duration, outcome, order flow, fills, and grid replacement.
- One correlation identifier shared by the completion record and the order, cancellation, fill, and reconciliation records produced by that tick.
- Bounded production log growth and per-service memory, with values an operator can override.
- Production fails closed before trading starts when the plan listener is reachable beyond loopback without a token.
- Keep changes out of order, risk, strategy, and fill semantics.

**Non-Goals:**

- No new metrics backend, log shipping, or tracing dependency.
- No change to grid replacement behavior; this change only measures it.
- No change to the engine's internal-only network topology.
- No rotation or resource policy for local development Compose.

## Decisions

### Tick-scoped statistics, an explicit outcome, and one deferred record

Add a `tickStats` value after the bot is loaded, confirmed running, and the tick claim succeeds. It carries a correlation ID, start time, outcome, failure detail, and counters for orders placed and canceled, fills, and grid states. The correlation ID is `tick-<bot id>-<start UnixNano>`.

`tickBot` uses one deferred function for the whole tick. On panic it sets `outcome = panicked`, records the recovered value, emits the completion record, and re-panics so `runDue` keeps its existing panic handling. On a normal return it selects the outcome from explicit markers: `failed` when any failure path marked it, `paused` when the tick paused the bot, and `completed` otherwise. The deferred function emits exactly one record and never runs twice.

The outcome is marked where the path is known, not inferred from a nil error:

- `tickFailure` marks `failed` before it returns, including the first two failures where it intentionally returns nil. It keeps the durable `last_error` and consecutive-failure work but stops emitting its duplicate warning.
- Every tick pause path marks `paused` before calling the pause helper.
- A direct `return err` from a database or strategy update leaves no marker; the deferred function marks `failed` because the returned error is non-nil.
- A panic is always `panicked`, even though the named return value is nil.

Counters are collected where the operation already happens: `placeOrders` returns the placed count, `applyCancels` and `syncGrid` report canceled orders, paper fills and live-applied fills report fill counts, and `syncGrid` reports created/matched/replaced plus level count. This avoids post-tick database queries and keeps the record accurate for the work the tick actually performed.

The correlation ID is threaded through the tick's order placement, cancellation, paper-fill, live-fill, and reconciliation helpers so their records carry the same value. A helper that cannot currently emit a record still accepts the ID for future use; the completion record is the canonical tick record.

Alternatives: query the database after each tick to reconstruct counts is more expensive and races with the next tick; log only at `runDue` cannot see grid or fill outcomes; inferring failure from a nil return is incorrect because `tickFailure` deliberately returns nil; a global metrics registry adds a dependency disproportionate to the need.

### Keep slog text output and use Docker's json-file driver

The engine keeps `slog.NewTextHandler` and adds key/value fields. Docker's `json-file` driver wraps each line as a JSON object with a `log` string; the completion message is stable (`bot tick completed`) with machine-friendly key/value fields inside that string. Documentation shows `jq -r .log` followed by field extraction rather than promising top-level JSON fields. The logging anchor sets `driver: json-file` explicitly because rotation options only apply to that driver.

### Static rotation with overridable memory limits

Add a shared YAML logging anchor (`driver: json-file`, `max-size: 10m`, `max-file: 3`, `compress: true`) to every production service. Add `mem_limit` to each service using environment-overridable defaults: app and Horizon 512 MiB, engine 512 MiB, PostgreSQL 512 MiB, scheduler 256 MiB, Redis 256 MiB, Nginx 128 MiB. Operators can raise a limit in `.env` without editing Compose. Values are deliberately conservative for a small NAS and are documented as caps to tune from `docker stats`, not reservations.

The default rotation favors bounded disk over long retention. A 60-second bot produces about 1,440 records per day, but ten one-second bots produce about 864,000 records per day; 10 MiB × 3 can rotate within hours at that rate. Documentation states the trade-off, shows how to raise the limits for the engine, and tells operators to check host RAM and disk before deploying.

Alternatives: a single global limit cannot protect one runaway service; Swarm `deploy.resources` is ignored by plain Compose; unbounded logs already caused the operational concern; JSON handler output would change every existing line and the local developer experience.

### Production-only fail-closed token validation before workers

The production Compose file requires `ENGINE_INTERNAL_TOKEN` with Compose's `${VAR:?message}` interpolation for both the app and engine services, so a missing value fails before containers start. Independently, `config.Load` validates that a production configuration with a non-loopback listener has a non-empty token and returns an error. `main` calls `config.Load` before creating the database pool, Redis client, consumers, scheduler, or market feed, so a missing token cannot begin trading work. Local development keeps the warning because its Compose network is trusted and its default token is empty.

Alternatives: validating next to the HTTP server starts workers first, which is too late for fail-closed; always failing would break the documented local stack; a warning alone leaves an exposed production endpoint unauthenticated; requiring TLS is out of scope for an internal Compose network.

## Risks / Trade-offs

- [High-frequency tick logs add volume] → one record per tick at Info, bounded by 10 MiB × 3 per service; documentation states the retention trade-off and how to raise it for one-second bots.
- [Counters may miss an operation performed inside a helper] → counters are added at the helper return points that already know the result, and a zero-count record still proves the tick ran.
- [Memory limits can cause OOM restarts on a busy NAS] → defaults are overridable per service, documented as caps, and health-gated deployment surfaces restart loops.
- [A missing token now blocks production startup] → this is the intended fail-closed behavior; the runbook documents generation and the Compose error names the variable.
- [Log field changes can break ad-hoc parsers] → existing messages are kept; only new fields and the completion message are added, and docs describe the container-level JSON shape.

## Migration Plan

No database change. Deploy the engine image and Compose file together. Generate a token, add it to the production `.env` for both services, and recreate the stack. Rollback is the previous engine image and Compose file; the old engine ignores the new fields and the token remains harmless.

## Open Questions

None.
