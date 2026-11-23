# Trading engine (Go)

This directory is the Go trading engine for the hybrid layout described in
`openspec/changes/adopt-hybrid-go-engine/`. **Phase 1 is a skeleton only:**

- no command/event stream consumer,
- no market-data WebSocket,
- no strategies, orders, fills, risk gates, or credential decryption,
- **no exchange calls of any kind**, live or testnet, and no public ticker fetches.

Laravel remains the only trading runtime until the later apply phases land.

## What it does today

| Route | Purpose |
| --- | --- |
| `GET /healthz` | Readiness. `200` only when PostgreSQL and Redis answer a ping; `503` otherwise. Response lists dependency names and `ok` / `unavailable` only, never the underlying error. |
| `GET /livez` | Liveness. Always `200` while the process is up; it never touches a dependency, so a database outage cannot restart the engine. |

The server listens on `ENGINE_LISTEN_ADDR` (default `:8080`). The Compose
healthcheck runs `/engine healthcheck`, which performs an in-container
`GET /healthz`; it never contacts an exchange. Start-up only configures a lazy
Postgres pool and a Redis client. Nothing connects, consumes, or trades.

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
| `TRADING_LIVE_ENABLED` | `false` | Read and logged only in phase 1; there are no trading paths yet |

`APP_KEY` is passed by Compose for later credential work but is **not read,
used, or logged** in phase 1. No secret is baked into the image or written to
disk.

## Heartbeat stub

`internal/heartbeat` contains the documentation-only heartbeat helper from the
change design: it SETs `engine:heartbeat` with a UTC RFC 3339 JSON payload and a
10-second TTL, and carries no secrets. **It is not wired into the server or any
loop.** Wiring it into the engine tick loop, and treating an expired key as a
dashboard warning only, belongs to a later phase.

## Layout

```text
engine/
  main.go                     process entrypoint and `healthcheck` subcommand
  internal/config/            environment parsing and Postgres DSN
  internal/health/            /healthz and /livez handlers
  internal/healthcheck/       in-container Compose probe
  internal/heartbeat/         documentation-only heartbeat stub
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

Tests use fake dependencies; they do not need a live Postgres, Redis, or
network access to an exchange.

## Docker

`engine/Dockerfile` is a multi-stage build: a `golang:1.24-alpine` build stage
compiles a static binary and `gcr.io/distroless/static:nonroot` runs it as a
non-root user. The local `docker-compose.yml` builds it as the `engine` service,
depends on healthy Postgres and Redis, and sets the same `APP_KEY` / `DB_*` /
`REDIS_*` / `TRADING_LIVE_ENABLED` (default `false`) environment as `app`. It
does not publish a host port.

Phase 1 deliberately does **not** edit `docker-compose.prod.yml` or `.github/`.
Adding the engine to GHCR, the QNAP Compose service/health/rollback flow, and a
CI `go test` step is deferred to the follow-up change after
`add-qnap-ci-deploy`. Until that follow-up ships, `make test` here is the
required local gate for Go changes.
