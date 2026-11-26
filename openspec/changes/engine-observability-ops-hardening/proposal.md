# Proposal

## Why

The engine reports almost nothing when ticks succeed, so health, latency, fill activity, and the reported grid churn (1,009 canceled orders against 38 fills) can only be investigated by querying the database after the fact. The production stack also has unbounded container logs and memory, and it does not require the internal plan-endpoint token even though that endpoint now exists.

## What Changes

- Emit one structured completion record per bot tick with the bot identifier, outcome, duration, and counts for orders placed and canceled, fills, and grid levels created, matched, or replaced.
- Keep failure and panic records, and extend them with the same bot, outcome, duration, and correlation fields; never log credentials, secrets, or signatures.
- Add JSON log rotation and per-service memory limits to every service in `docker-compose.prod.yml`, sized for the documented QNAP host.
- Require `ENGINE_INTERNAL_TOKEN` in the production composition, keep the engine port unpublished, and make the engine fail closed when its HTTP listener is reachable beyond loopback without a token.
- Document token generation/rotation, the tick-record fields, and the production log and memory bounds.
- No trading, strategy, risk, or order-semantics changes.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: The Observability requirement gains an explicit per-tick completion record with duration and operation counts, so tick health and grid churn are measurable from logs alone.
- `deployment`: The Production composition requirement gains bounded container logs and memory, and Runtime configuration and secrets gains a required internal engine token for production.

## Impact

- Go engine: tick logging in `engine/internal/runtime`, plan listener startup validation, and logging tests.
- Compose: `docker-compose.prod.yml` logging and resource limits plus an explicit token mapping; `.env.example` and deployment documentation.
- Documentation: `docs/engine.md`, `docs/deployment.md`, and `docs/runtime.md` for the observable tick fields and token requirement.
- No database migration and no change to Laravel trading behavior. The engine remains internal-only; only the web server publishes a host port.
