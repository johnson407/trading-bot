# Trading engine (Go)

This directory is the Go trading engine for the hybrid layout described in
`openspec/changes/adopt-hybrid-go-engine/`. **Phase 3 adds the public
market-data library and its opt-in process hook; there are still:**

- no strategies, orders, fills, risk gates, or credential decryption;
- no private, signed, or user-data exchange calls and no API keys;
- **no exchange connection by default** — the Compose service sets no market
  data endpoints, so the feed stays idle.

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

### Public market data (phase 3)

`internal/marketdata` implements the Binance public feed required by design §5.
It is **idle unless explicitly configured**:

- subscribes to `<symbol>@trade` and `<symbol>@bookTicker` public streams, which
  supply last, bid, and ask without credentials;
- reconnects with exponential backoff;
- detects a trade-id gap or an unexpected quiet period, takes a public REST
  `24hr` ticker snapshot, then resubscribes;
- polls the public REST ticker while the socket cannot be established and marks
  the feed degraded (`marketdata.degraded` event plus a structured log line);
- marks recovery with `marketdata.recovered`.

The feed never sends API keys, never touches signed or private endpoints, and
never places orders. Phase 3 has no running-bot tick loop, so the process hook
uses the optional `ENGINE_MARKET_DATA_SYMBOLS` list; phase 4 will pass the
running bots' symbols instead. `/healthz` and `/livez` do not depend on the
feed.

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
| `ENGINE_MARKET_DATA_WS_URL` | empty | Full public WebSocket endpoint, e.g. `wss://stream.binance.com:9443/ws`. Empty means market data stays idle |
| `ENGINE_MARKET_DATA_REST_URL` | empty | Public REST base URL, e.g. `https://api.binance.com`. Empty means market data stays idle |
| `ENGINE_MARKET_DATA_SYMBOLS` | empty | Comma-separated opt-in symbol list; empty also keeps the feed idle |
| `ENGINE_MARKET_DATA_QUIET_AFTER` | `30s` | Maximum silence before a REST snapshot and resubscribe |
| `ENGINE_MARKET_DATA_REST_POLL_INTERVAL` | `5s` | REST ticker period while the socket is unavailable |
| `ENGINE_MARKET_DATA_RECONNECT_MIN` / `_MAX` | `500ms` / `30s` | Exponential reconnect backoff bounds |
| `ENGINE_MARKET_DATA_REQUEST_TIMEOUT` | `10s` | Dial and HTTP request timeout |

Both market-data URLs must be set together; setting only one is a startup
error. Leaving both empty is the default and keeps `marketdata.Run` idle, so
the engine process opens **no** exchange connection. Local Compose does not set
any `ENGINE_MARKET_DATA_*` variable.

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
  internal/marketdata/        public Binance WS feed, gap/quiet recovery, REST fallback
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

Consumer, idempotency, protocol, heartbeat, and market-data tests use
`miniredis`, fake WebSocket servers, and `httptest`. They never contact
Binance or any other exchange, need no credentials, and do not require the
Compose Redis or a live Postgres. The market-data suite covers subscription,
reconnect with backoff, trade-id gap detection, quiet-period recovery, periodic
REST fallback, and disconnect → REST snapshot → resumed socket.

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
