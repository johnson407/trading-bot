# Design

## Context

See `proposal.md` for motivation and the `bot-runtime` and `deployment` deltas for the contract. The engine is one Go process using `log/slog` to stdout. `Runtime.tickBot` has many early returns and currently emits no success record; `runDue` logs only scheduler failures and panics. Production runs seven Compose services behind Docker's `json-file` driver, with only Nginx publishing a host port. The plan endpoint already authenticates with a bearer token when `ENGINE_INTERNAL_TOKEN` is set, but the production composition does not require it.

## Goals / Non-Goals

**Goals:**

- One completion record per tick, including successful ticks, with enough fields to measure duration, order flow, fills, and grid replacement.
- Bounded production log growth and per-service memory, with values an operator can override.
- Production fails closed when the plan listener is reachable beyond loopback without a token.
- Keep changes out of order, risk, strategy, and fill semantics.

**Non-Goals:**

- No new metrics backend, log shipping, or tracing dependency.
- No change to grid replacement behavior; this change only measures it.
- No change to the engine's internal-only network topology.
- No rotation or resource policy for local development Compose.

## Decisions

### Tick-scoped statistics and one deferred completion record

Add a `tickStats` value created after the bot is loaded and confirmed running. It carries a correlation ID, start time, outcome, and counters for orders placed and canceled, fills, and grid states. `tickBot` uses a named return and a deferred `logTickCompletion`, so every path that actually started a tick emits exactly one record. The deferred logger sets `outcome` to `failed` when the returned error is non-nil, `paused` when a pause path marked the stats, and `completed` otherwise. The correlation ID is `tick-<bot id>-<start UnixNano>`; it is included in the completion record and can be added to order/cancel records created within the tick.

Counters are collected where the operation already happens: `placeOrders` returns the placed count, `applyCancels` and `syncGrid` report canceled orders, paper fills and live-applied fills report fill counts, and `syncGrid` reports created/matched/replaced plus level count. This avoids post-tick database queries and keeps the record accurate for the work the tick actually performed. `tickFailure` keeps its durable `last_error` and failure-count work but stops emitting a duplicate warning; the deferred record is the canonical per-tick record. `runDue` retains the panic log and adds duration.

Alternatives: query the database after each tick to reconstruct counts is more expensive and races with the next tick; log only at `runDue` cannot see grid or fill outcomes; a global metrics registry adds a dependency disproportionate to the need.

### Keep slog text output and let Docker supply JSON framing

The engine keeps `slog.NewTextHandler` and adds key/value fields. Docker's `json-file` driver already wraps each line as JSON for collection, and this avoids changing every existing log line and the local developer experience. The completion message is stable (`bot tick completed`) with machine-friendly field names.

### Static rotation with overridable memory limits

Add a shared YAML logging anchor (`max-size: 10m`, `max-file: 3`, `compress: true`) to every production service. Add `mem_limit` to each service using environment-overridable defaults: app and Horizon 512 MiB, engine 512 MiB, PostgreSQL 512 MiB, scheduler 256 MiB, Redis 256 MiB, Nginx 128 MiB. Operators can raise a limit in `.env` without editing Compose. Values are deliberately conservative for a small NAS and are documented as starting points to tune from `docker stats`.

Alternatives: a single global limit cannot protect one runaway service; Swarm `deploy.resources` is ignored by plain Compose; unbounded logs already caused the operational concern.

### Production-only fail-closed token validation

The production Compose file requires `ENGINE_INTERNAL_TOKEN` with Compose's `${VAR:?message}` interpolation for both the app and engine services, so a missing value fails before containers start. The engine startup check replaces the current warning with an error only when `APP_ENV=production`, the listen host is not loopback, and the token is empty. Local development keeps the warning because its Compose network is trusted and its default token is empty.

Alternatives: always failing would break the documented local stack; a warning alone leaves an exposed production endpoint unauthenticated; requiring TLS is out of scope for an internal Compose network.

## Risks / Trade-offs

- [High-frequency tick logs add volume] → one record per tick at Info, bounded by 10 MiB × 3 per service; a 60-second bot produces 1,440 records per day.
- [Counters may miss an operation performed inside a helper] → counters are added at the helper return points that already know the result, and a zero-count record still proves the tick ran.
- [Memory limits can cause OOM restarts on a busy NAS] → defaults are overridable per service and documented; health-gated deployment surfaces restart loops.
- [A missing token now blocks production startup] → this is the intended fail-closed behavior; the runbook documents generation and the Compose error names the variable.
- [Log field changes can break ad-hoc parsers] → existing messages are kept; only new fields and the completion message are added.

## Migration Plan

No database change. Deploy the engine image and Compose file together. Generate a token, add it to the production `.env` for both services, and recreate the stack. Rollback is the previous engine image and Compose file; the old engine ignores the new fields and the token remains harmless.

## Open Questions

None.
