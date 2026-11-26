# Proposal

## Why

The engine reports almost nothing when ticks succeed, so health, latency, fill activity, and the reported grid churn (1,009 canceled orders against 38 fills) can only be investigated by querying the database after the fact. The production stack also has unbounded container logs and memory, and it does not require the internal plan-endpoint token even though that endpoint now exists.

## What Changes

- Emit exactly one structured completion record per started bot tick with the bot identifier, outcome (`completed`, `paused`, `failed`, or `panicked`), duration, and counts for orders placed and canceled, fills, and grid levels created, matched, or replaced.
- Keep failure and panic records, and give them the same bot, outcome, duration, and correlation fields; never log credentials, secrets, or signatures.
- Carry the tick correlation identifier into order, cancellation, fill, and reconciliation records produced by that tick, so one tick can be traced end to end.
- Add JSON log rotation, an explicit `json-file` driver, and per-service memory limits to every service in `docker-compose.prod.yml`, sized for the documented QNAP host.
- Require `ENGINE_INTERNAL_TOKEN` in the production composition, keep the engine port unpublished, and make the engine refuse to start before any trading worker when its HTTP listener is reachable beyond loopback without a token.
- Document token generation/rotation, the tick-record fields, the production log and memory bounds, and the expected log retention.
- No trading, strategy, risk, or order-semantics changes.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: The Observability requirement gains an explicit per-tick completion record with outcome, duration, operation counts, and a correlation identifier shared by records produced by that tick, so tick health and grid churn are measurable from logs alone.
- `deployment`: The Production composition requirement gains bounded container logs and memory for all seven services, and Runtime configuration and secrets gains a required internal engine token that fails closed before workers start.

## Impact

- Go engine: tick logging in `engine/internal/runtime`, correlation propagation, startup token validation, and logging tests.
- Compose: `docker-compose.prod.yml` logging, resource limits, and an explicit token mapping; `.env.example` and deployment documentation.
- Documentation: `docs/engine.md`, `docs/deployment.md`, and `docs/runtime.md` for the observable tick fields, token requirement, and log retention.
- No database migration and no change to Laravel trading behavior. The engine remains internal-only; only the web server publishes a host port.
