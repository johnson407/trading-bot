# Trading engine (Go)

This directory is the Go trading engine for the hybrid layout described in
`openspec/changes/adopt-hybrid-go-engine/`. **Phase 2 implements the command
contract, at-least-once consumer, idempotency, and heartbeat only:**

- no market-data WebSocket,
- no strategies, orders, fills, risk gates, or credential decryption,
- **no exchange calls of any kind**, live or testnet, and no public ticker fetches.

Laravel remains the only trading runtime until the later apply phases land. The
phase-2 command processor rejects commands it receives (`handler_not_available`)
and never applies a trading effect.

The full protocol, ordering, dead-letter, kill-switch, heartbeat, and status
ownership contract is documented in [`../docs/engine.md`](../docs/engine.md).

## What it does today

| Route | Purpose |
| --- | --- |
| `GET /healthz` | Readiness. `200` only when PostgreSQL and Redis answer a ping and `engine:heartbeat` is fresh; `503` otherwise. Response lists dependency names and `ok` / `unavailable` only, never the underlying error. |
| `GET /livez` | Liveness. Always `200` while the process is up; it never touches a dependency, so a database outage cannot restart the engine. |

On start the process:

1. writes `engine:heartbeat` (JSON `ts`, `revision`, `running_bots`) and
   refreshes it every five seconds with a ten-second TTL;
2. ensures the `engine.commands` consumer group, auto-claims pending entries
   idle for at least 30 seconds, and consumes the stream with at-least-once
   delivery;
3. stores `engine:cmd:{id}` after durable effects (or a definitive rejection)
   and only then sends `XACK`;
4. dead-letters an entry to `engine.commands.dlq` after five failed attempts and
   acknowledges the original so the group is not blocked.

The server listens on `ENGINE_LISTEN_ADDR` (default `:8080`). The Compose
healthcheck runs `/engine healthcheck`, which performs an in-container
`GET /healthz`; it never contacts an exchange.

## Configuration

All values come from the environment, mirroring the `app` service:

| Variable | Default | Notes |
| --- | --- | --- |
| `ENGINE_LISTEN_ADDR` | `:8080` | `host:port` served by the engine and probed by the healthcheck |
| `APP_ENV` | `local` | Logged only |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | Compose sets `postgres` / `5432` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `trading_bot` / `trading_bot` / empty | Same values as Laravel |
| `DB_SSLMODE` | `disable` | Local Compose Postgres has no TLS |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | Compose sets `redis` / `6379` |
| `REDIS_PASSWORD` / `REDIS_DB` | empty / `0` | Optional |
| `TRADING_LIVE_ENABLED` | `false` | Read and logged only; there are no trading paths yet |

`APP_KEY` is passed by Compose for later credential work but is **not read,
used, or logged** in phase 2. No secret is baked into the image or written to
disk.

## Layout

```text
engine/
  main.go                     process entrypoint, consumer/heartbeat wiring, `healthcheck`
  internal/config/            environment parsing and Postgres DSN
  internal/protocol/          v1 envelope, stream names, command/event types
  internal/consumer/          at-least-once group consumer, PEL reclaim, DLQ
  internal/idempotency/       engine:cmd:{id} store (>= 7 day TTL)
  internal/heartbeat/         engine:heartbeat writer and freshness check
  internal/health/            /healthz and /livez handlers
  internal/healthcheck/       in-container Compose probe
  Dockerfile                  multi-stage build, distroless non-root runtime
  Makefile                    all targets run Go through Docker
```

## Tests

There is no Go toolchain on the host. `make test` runs the suite in the pinned
official image (`golang:1.24-alpine`, the same version as the Dockerfile build
stage) and exits non-zero on failure:

```bash
cd engine
make test
```

This host (and some apply environments) may not have `make` or a local Go toolchain.
The Makefile recipe is equivalent to:

```bash
cd engine
docker run --rm -v "$PWD:/src" -w /src golang:1.24-alpine go test ./...
```

Other targets: `make vet`, `make fmt` (lists unformatted files), `make tidy`,
and `make build` (builds the `trading-bot-engine` image).

Consumer, idempotency, protocol, and heartbeat tests use `miniredis` or fakes.
They do not need a live Postgres, the Compose Redis, or network access to an
exchange.

## Docker

`engine/Dockerfile` is a multi-stage build: a `golang:1.24-alpine` build stage
compiles a static binary and `gcr.io/distroless/static:nonroot` runs it as a
non-root user. The local `docker-compose.yml` builds it as the `engine` service,
depends on healthy Postgres and Redis, and sets the same `APP_KEY` / `DB_*` /
`REDIS_*` / `TRADING_LIVE_ENABLED` (default `false`) environment as `app`. It
does not publish a host port.

This change deliberately does **not** edit `docker-compose.prod.yml` or
`.github/`. Adding the engine to GHCR, the QNAP Compose service/health/rollback
flow, and a CI `go test` step is deferred to the follow-up change after
`add-qnap-ci-deploy`. Until that follow-up ships, `make test` here is the
required local gate for Go changes.
