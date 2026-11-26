# Trading engine (Go)

This directory is the Go trading engine for the hybrid layout described in
`openspec/changes/adopt-hybrid-go-engine/`. The bot runtime — control commands,
due-check ticks, grids, paper fills, live placement/reconciliation, risk/exit
guards, and backtests — runs here. Live execution is implemented but gated:

- paper orders and fills are simulated and persisted by the engine;
- live placement, cancellation, and reconciliation call private exchange
  endpoints only while `TRADING_LIVE_ENABLED=true`; the default is `false`;
- a live bot start while the gate is closed pauses the bot with a clear
  `last_error`, records a risk event, and never falls back to paper trading;
- Binance is the first live venue, with its official testnet selected through
  `TRADING_EXCHANGE_SANDBOX`; MAX is implemented against fakes only in this
  build and refuses live/testnet credentials;
- Laravel keeps the live gate, confirmation, balance pre-check, and the
  emergency `CancelBotOrders` path;
- **public market data is enabled** — the runtime follows bot symbols on
  Binance's public WebSocket feed, with a bounded REST fallback for ticks.

The command processor handles `bot.start`, `bot.pause`, `bot.stop`,
`bot.config`, `order.cancel`, `kill_switch`, `backtest.run`, and
`backtest.sweep`.

The full protocol, ordering, dead-letter, kill-switch, heartbeat, and status
ownership contract is documented in [`../docs/engine.md`](../docs/engine.md).

PHP golden fixtures for the later phase-4 ports are committed under
`../tests/golden/engine/` (see `../tests/golden/README.md`). They are a snapshot
of current PHP behaviour only; the Go parity tests read the committed originals
directly from the repository root and do not vendor a second copy.

## What it does today

| Route | Purpose |
| --- | --- |
| `GET /healthz` | Readiness. `200` only when PostgreSQL and Redis answer a ping and `engine:heartbeat` is fresh; `503` otherwise. Response lists dependency names and `ok` / `unavailable` only, never the underlying error. |
| `GET /livez` | Liveness. Always `200` while the process is up; it never touches a dependency, so a database outage cannot restart the engine. |
| `POST /v1/plan/evaluate` | Internal setup-plan evaluation used by Laravel preview and create/edit validation. Never persists a bot and never makes a private exchange call. Bound to the internal Compose network; set `ENGINE_INTERNAL_TOKEN` if the port is ever published beyond it. |

On start the process:

1. writes `engine:heartbeat` (JSON `ts`, `revision`, `running_bots`) and
   refreshes it every five seconds with a ten-second TTL;
2. ensures the `engine.commands` and `engine.backtests` consumer groups,
   auto-claims pending entries idle for at least 30 seconds, and consumes each
   stream with at-least-once delivery;
3. stores `engine:cmd:{id}` after durable effects (or a definitive rejection)
   and only then sends `XACK`;
4. dead-letters an entry to `engine.commands.dlq` after five failed attempts and
   acknowledges the original so the group is not blocked.

### Public market data (phase 3)

`internal/marketdata` implements the Binance public feed required by design §5.
It is **idle until a symbol is needed**:

- subscribes to `<symbol>@trade` and `<symbol>@bookTicker` public streams, which
  supply last, bid, and ask without credentials;
- follows the union of running/starting Binance bots plus the optional
  `ENGINE_MARKET_DATA_SYMBOLS` watchlist, subscribing and unsubscribing as bots
  start and stop;
- reconnects with exponential backoff;
- detects a trade-id gap or an unexpected quiet period, takes a public REST
  `24hr` ticker snapshot, then resubscribes;
- polls the public REST ticker while the socket cannot be established and marks
  the feed degraded (`marketdata.degraded` event plus a structured log line);
- marks recovery with `marketdata.recovered`.

The feed never sends API keys, never touches signed or private endpoints, and
never places orders. Ticks read its cached last price through
`marketdata.PriceSource`; a cache miss or a quote older than
`ENGINE_MARKET_DATA_STALE_AFTER` (default `max(15s, TRADING_TICK_INTERVAL / 2)`)
triggers one public REST snapshot, and a failed snapshot fails the tick rather
than trading on an old price. `/healthz` and `/livez` do not depend on the feed.

The server listens on `ENGINE_LISTEN_ADDR` (default `:8080`). The Compose
healthcheck runs `/engine healthcheck`, which performs an in-container
`GET /healthz`; it never contacts an exchange.

### Tick, grid, paper-fill, and strategy ports (phase 4.2–4.8)

`internal/tick` ports the PHP `bots:dispatch` due-check (60s default, 1s
minimum), the per-interval cache guard, the `RunBotTick` unique/overlap policy,
and `BotHealthMonitor`'s `max(stale, interval + health)` threshold. It also
provides an in-process scheduler and one-evaluation-per-bot tracker. None of it
is started by `main`, and it has no database or Redis source yet.

`internal/grid` ports `GridGenerator` (arithmetic, geometric, and dynamic
ladders plus validation), `GridDerivation`, and the `BotTickPipeline` grid
create/match/cancel/recenter path. These packages are proven against the
committed `tick_loop.json`, `grid_generation.json`, and `grid_sync.json`
fixtures. The `grid_sync` fixture is end-to-end for the PHP pipeline; the test
asserts its grid and order lifecycle, and after the strategy port it also
models the simple-grid paper fill/re-arm so the fill steps match the exact
orders and level states.

`internal/decimal` implements PHP `Decimal` scale-16 bcmath semantics: add/sub
and multiply truncate at 16 places, divide truncates at 17 then rounds half-up
to 16, comparisons truncate at 16, and values reject floats.

### Paper fills (phase 4.4)

`internal/paperfill` ports `PaperExchangeClient::qualifyingFills`: open and
partially filled limit orders fill when the tick's observed last price crosses
their limit (buy at or below, sell at or above). Fills are ordered
closest-to-market first, ties break on the lower price, quantities are the
remaining amount, and the fee is `price * quantity * fee_rate`. It is a pure
library and only sees the price observed by a tick, so an intra-tick print that
crosses and returns before the next tick does not fill an order. The package is
proven against the committed `paper_fills.json` fixture, including the
cross-and-recross-between-ticks scenario. Nothing places or persists orders yet.

### Strategies (phase 4.5–4.8)

`internal/strategy` ports the pure PHP strategy value objects and
`SimpleGridStrategy` / `DynamicGridStrategy` / `MartingaleGridStrategy` /
`TrailingGridStrategy`: initialize, tick, fill, cancel, `shouldStop`, and
`getGridLevels`. It also ports the shared `GeneratesGridLevels` ladder,
stable-price sort, duplicate detection, and disarmed-level re-arm behaviour.
Strategies stay pure: they receive an `Input` and return a `Decision`; they
never read the database, an exchange, or PHP state.

All four strategies are proven against every case in the committed
`strategies.json` fixture (`simple-grid`, `dynamic-grid`, `martingale-grid`, and
`trailing-grid`), including arithmetic and geometric ladders, order suppression
at open levels, adjacent opposite orders after a fill, fill/pair re-arming on
the next tick, dynamic volatility spacing, interval suppression, recalculation
with order cancels and re-orders, martingale size growth with the max-quantity
and max-increases caps, trailing recenter beyond the configured distance with
outside-order cancellation, cancel bookkeeping, and the recorded validation
errors. The `grid_sync.json` simple-grid and trailing-grid scenarios also model
the paper fill/re-arm and recenter path, so their steps assert the exact orders
and grid level states instead of only the grid structure. A level keeps its
center-based plan side; the post-fill order can be the opposite side at that
level, and the order side is authoritative for fills and inventory.

Nothing here is wired into `main`, dispatched as a tick, placed, persisted, or
connected to an exchange.

### Risk gates and credential decryption (phase 5.1–5.7)

`internal/risk` ports the blocking PHP risk gates as an in-memory library. It
never opens an exchange connection, never signs a request, and never places an
order.

- `LiveModeGuard` enforces `TRADING_LIVE_ENABLED`, which defaults to `false`,
  before any private call. A live start also requires the exact
  `<bot name> on <exchange>` confirmation and enough free quote balance. A
  rejected start stays idle; a live bot whose gate closes mid-run is paused and
  its open orders are canceled, never converted to paper.
- `RiskGuard` blocks non-positive orders, sells above uncommitted inventory
  (without a risk event), projected position notional above the limit, and
  orders above the open-order cap.
- `DailyLossMonitor` pauses and cancels on per-bot and account-wide loss,
  keeping a bot-scope baseline in the engine-owned
  `settings.daily_loss_baseline` key and the account baseline on the user
  `risk_limits` row. It never resumes a paused bot.
- `Runtime.dailyLoss` (the SQL-backed tick path) resolves the per-bot limit
  from a bot-scope override, then the account row, then the built-in default,
  per column. An unreadable stored limit or account day-start baseline fails
  the tick closed instead of trading on fallback values.
- `ExitGuardMonitor` implements stop-loss, take-profit, and range exits with
  stop-loss → take-profit → range precedence. It pauses and cancels but never
  market-flattens, and a second evaluation of a paused bot is a no-op.
- `ConsecutiveFailureTracker` pauses at the configured threshold, cancels open
  orders, and keeps a stopped bot stopped while still reporting the failure.
- `ExchangeAllowlist` ports `TRADING_EXCHANGES` creation validation and adds
  the missing Go start/placement blocks, so a directly inserted non-allowlisted
  exchange cannot start or place. The committed fixture's
  `direct_insert_current_php` cases document the current-PHP gap only.

`internal/credentials` decrypts Eloquent `encrypted` / `Crypt::encryptString`
payloads in memory: AES-256-CBC, `base64:` 32-byte keys, HMAC-SHA256 over
`iv.value`, the JSON `iv` / `value` / `mac` / `tag` layout, no PHP
unserialization, and Laravel's current-then-previous key order. It never reads
`.env` or `APP_KEY` itself, never logs a key, payload, or plaintext, and
exports `Redact` / `SafeError` for diagnostics. The golden test uses the
committed stub ciphertext fixture, not `Crypt::encrypt()` and not an
environment value.

All risk and credential packages are proven against `risk.json` and
`credentials_ciphertext.json`. The runtime uses the risk package for daily-loss
and protective exits; paper order placement also applies its own open-order
inventory and position reservations. Live order dispatch remains disabled.

### Stale checks, risk-event throttling, and shutdown

The runtime scheduler reconciles every second. A running bot is paused as stale
only when **both** its last successful tick and this process's first observation
of it running are older than `max(180s, tick_interval_seconds + 30s)`. A restart
therefore gives every running bot one full threshold to tick again; engine
downtime alone does not pause it. If ticks keep failing through the grace, the
normal `bot.stale` / `risk.stale_tick` pause-and-cancel path runs. Grace entries
are dropped once a bot is no longer running.

Position-limit blocks are counted per placement attempt. The runtime writes one
`risk.position_limit` event with `orders_skipped` and `projected_notional`
instead of one event per blocked order, and suppresses repeats for the same bot
and event type for five minutes.

On `SIGINT` or `SIGTERM` the engine cancels its root context, shuts down the
HTTP server, and waits up to 20 seconds for the scheduler (including in-flight
ticks), both Redis consumers, the heartbeat, the market-data feed and symbol
syncer, and the stale-backtest sweeper. Both Compose files set
`stop_grace_period: 25s`, so Docker does not preempt that drain.

### Backtest replay (phase 6.1–6.4)

`internal/backtest` ports the PHP replay engine and runs it from the dedicated
backtest consumer:

- Laravel creates the queued `backtest_runs` / `backtest_sweeps` rows and
  publishes `backtest.run` / `backtest.sweep`; the engine never creates sweep
  rows or the strategy snapshot. `PlanSweep` exists only as the parity helper
  used by the committed sweep golden fixture; production sweeps execute the
  runs Laravel already inserted.
- A run is claimed with `pending → running` in one atomic update. A duplicate
  command for an already running or finished run is ignored.
- The replay has a hard `BACKTEST_JOB_TIMEOUT` deadline. Exceeding it fails the
  run with the stale-worker message, matching the PHP job timeout followed by
  `backtests:fail-stale`. A panic in replay is recovered and recorded as a
  failed run rather than crashing the engine.
- Candles are read from the engine-owned `market_candles` cache, restricted to
  timestamps aligned to the timeframe. A missing candle inside the range fails
  the run with `Historical data gap at <time> (<n> candle missing).` (or
  `candles`); an empty range fails with `No historical data available.` If the
  cache is empty or the range is incomplete, the engine fetches the public
  kline range, stores it in `market_candles`, and retries the read.
- Replay is deterministic and side-effect free: initialization, tick, fill,
  cancel, and stop are reported to the same strategy ports used by live
  execution, and no bot, order, fill, risk, or activity row is written outside
  `backtest_runs`, `backtest_fills`, and `market_candles` reads.
- A successful run writes `completed`, metrics, the downsampled equity curve,
  and `backtest_fills` in one transaction. A failed run keeps a trimmed,
  500-character non-secret error.
- A sweep command executes the sweep's runs in creation order. Runs stay
  `pending` until each is claimed, so a sweep can wait on its stream without
  ageing out.
- Backtest commands are consumed from a dedicated `engine.backtests` stream and
  consumer group, so a long replay cannot delay bot, order, or kill-switch
  commands on `engine.commands`.
- A stale sweeper runs every five minutes. It fails `running` runs whose last
  update is older than `BACKTEST_JOB_TIMEOUT + 60s` with
  `The backtest worker stopped before the run finished.` Pending runs are
  protected while they wait in the Redis PEL; only a pending run older than 24
  hours is failed as an orphan. Laravel no longer schedules
  `backtests:fail-stale`.

The 14 committed replay cases and two sweep cases in
`../tests/golden/engine/backtest_*.json` are asserted by `make test`, including
errors, fees, risk/exit-guard replay, and final strategy state.

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
| `TRADING_LIVE_ENABLED` | `false` | Live gate enforced by `internal/risk`; false keeps live bots paused |
| `TRADING_TICK_INTERVAL` | `60` | Seconds; sets the default stale-price bound (`max(15s, interval / 2)`) |
| `TRADING_EXCHANGES` | `binance` | Comma-separated, case-sensitive exchange allowlist |
| `TRADING_DEFAULT_EXCHANGE` | `binance` | Used when bot creation submits a blank exchange |
| `ENGINE_MARKET_DATA_WS_URL` | empty | Full public WebSocket endpoint, e.g. `wss://stream.binance.com:9443/ws`. Empty means market data stays idle |
| `ENGINE_MARKET_DATA_REST_URL` | empty | Public REST base URL, e.g. `https://api.binance.com`. Empty means market data stays idle |
| `ENGINE_MARKET_DATA_SYMBOLS` | empty | Optional comma-separated watchlist pinned in addition to running bots' symbols |
| `ENGINE_MARKET_DATA_STALE_AFTER` | `max(15s, tick / 2)` | Maximum cached-quote age a tick may use; a stale quote triggers a public REST snapshot |
| `ENGINE_MARKET_DATA_QUIET_AFTER` | `30s` | Maximum silence before a REST snapshot and resubscribe |
| `ENGINE_MARKET_DATA_REST_POLL_INTERVAL` | `5s` | REST ticker period while the socket is unavailable |
| `ENGINE_MARKET_DATA_RECONNECT_MIN` / `_MAX` | `500ms` / `30s` | Exponential reconnect backoff bounds |
| `ENGINE_MARKET_DATA_REQUEST_TIMEOUT` | `10s` | Dial and HTTP request timeout |
| `BACKTEST_JOB_TIMEOUT` | `600s` | Hard per-run replay deadline and stale cutoff base; stale failures start after timeout + 60s |
| `BACKTEST_MAX_FILLS` | `25000` | Maximum fills before a run fails, mirroring `config/backtest.php` |
| `BACKTEST_EQUITY_POINTS` | `1000` | Maximum persisted equity points, mirroring `config/backtest.php` |
| `BACKTEST_PROGRESS_INTERVAL` | `500` | Candles between progress writes, mirroring `config/backtest.php` |

Both market-data URLs must be set together; setting only one is a startup
error. Leaving both empty keeps the feed idle, so the engine process opens
**no** exchange connection and each tick uses one public REST snapshot. Local
Compose sets the public Binance URLs, `TRADING_TICK_INTERVAL`, and no pinned
symbol list, so subscriptions follow running bots.

`APP_KEY` and `APP_PREVIOUS_KEYS` are read only to decrypt stored exchange
credentials in memory for live placement. The key material is never logged,
written to disk, or attached to an event. The golden tests use only the
committed stub key. No secret is baked into the image.

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
  internal/plan/              internal setup-plan evaluator and HTTP handler
  internal/exchange/          gated live clients (Binance, MAX), rules, backoff, redaction
  internal/decimal/           PHP Decimal scale-16 bcmath-compatible arithmetic
  internal/tick/              bots:dispatch due-check, overlap, stale threshold
  internal/grid/              grid generation, derivation, create/match/recenter
  internal/paperfill/         qualifyingFills paper-fill selection
  internal/strategy/          pure simple/dynamic/martingale/trailing strategy ports
  internal/risk/              live gate, risk/loss/exit/failure gates, exchange allowlist
  internal/credentials/       AES-256-CBC Crypt::encryptString-compatible decryption
  internal/backtest/          deterministic replay, sweep plans, Postgres runner, stale failer
  internal/goldenfixture/     locates tests/golden/engine for parity tests
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
The Makefile mounts the **repository root** (not only `engine/`) so the tests can
read `tests/golden/engine/*.json`. The equivalent recipes are:

```bash
# from the repository root
docker run --rm -v "$PWD:/src" -w /src/engine \
  -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./...

# from engine/
docker run --rm -v "$PWD/..:/src" -w /src/engine \
  -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./...
```

Other targets: `make vet`, `make fmt` (lists unformatted files), `make tidy`,
and `make build` (builds the `trading-bot-engine` image).

The PostgreSQL-backed runtime tests are skipped unless `RUNTIME_TEST_DSN` is
set; CI points it at the test database after Laravel has migrated it. The PHP
suite's plan-preview tests call the real evaluator from `ENGINE_PLAN_URL` or a
local Go toolchain, so run the engine (or `go run . plan-server`) before Pest.

Consumer, idempotency, protocol, heartbeat, and market-data tests use
`miniredis`, fake WebSocket servers, and `httptest`. They never contact
Binance or any other exchange, need no credentials, and do not require the
Compose Redis or a live Postgres. The market-data suite covers subscription,
reconnect with backoff, trade-id gap detection, quiet-period recovery, periodic
REST fallback, and disconnect → REST snapshot → resumed socket.

The risk parity tests read `risk.json` and `credentials_ciphertext.json` and
cover every committed live-start and closed-gate tick, position/open-order/
inventory reservation, per-bot and account-wide daily loss (including
baseline reset, isolation, unrealized marks, and no auto-resume), every exit
rule and monitor path (including cancel failure and paused no-op), the
consecutive-failure sequences, allowlist creation validation, and decrypting
the stub ciphertexts with current and previous keys plus redaction.

The tick, grid, paper-fill, and strategy parity tests read the committed PHP
golden fixtures. They cover due-check interval/minimum/paused-stopped skips, the
cache guard, job uniqueness/overlap policy, stale thresholds,
arithmetic/geometric/dynamic grid generation, derivation errors, grid creation,
level matching, fill-driven sync, trailing recenter, paper-fill crossing and
ordering, partial quantities, fees, skipped filled/canceled orders,
tick-time cross-and-recross for both sides, and every simple-grid,
dynamic-grid, martingale-grid, and trailing-grid strategy case (including the
exact decimal state, levels, order intents, cancels, cap events, trailing
recenters, and validation errors).

The backtest parity tests read `backtest_replay.json` and `backtest_sweep.json`.
They compare all 14 replay cases (metrics, fills, equity curve, final state,
fees, risk/exit-guard behavior) and both sweep cases semantically through the
fixture decoder, plus the exact no-candle, max-fill, gap, and stale-run
messages. `TestStoreExecuteRunIntegration` is opt-in and exercises the real
PostgreSQL claim/read/write path:

```bash
REPO_ROOT=$(git rev-parse --show-toplevel)
BACKTEST_TEST_DSN='postgres://user:pass@host:5432/trading_bot_test?sslmode=disable' \
  docker run --rm --network <compose-network> -v "$REPO_ROOT:/src" -w /src/engine \
  -e BACKTEST_TEST_DSN -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./internal/backtest -run TestStoreExecuteRunIntegration
```

The runtime control/tick path has its own opt-in PostgreSQL test:

```bash
REPO_ROOT=$(git rev-parse --show-toplevel)
RUNTIME_TEST_DSN='postgres://user:pass@host:5432/trading_bot_test?sslmode=disable' \
  docker run --rm --network <compose-network> -v "$REPO_ROOT:/src" -w /src/engine \
  -e RUNTIME_TEST_DSN -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./internal/runtime -run TestRuntimeControlAndTickIntegration
```

It covers start, due tick, grid/order persistence, paper fills, single-order
cancel, stop, restart, and kill switch.

The default `make test` gate has no PostgreSQL service, so the DB runner,
stale-run, candle-backfill, and runtime paths are covered by those opt-in tests.
CI still needs a database service before those tests can become a merge gate.

### Opt-in Binance testnet proof

`TestBinanceTestnetPlaceCancelReconcile` is skipped unless explicitly enabled.
It places, looks up, reconciles, and cancels one resting order on **Binance
testnet only**:

```bash
BINANCE_TESTNET=1 \
BINANCE_TESTNET_API_KEY=... \
BINANCE_TESTNET_API_SECRET=... \
  go test -tags binance_testnet ./internal/exchange -run TestBinanceTestnetPlaceCancelReconcile -v
```

The build tag means the default `go test ./...` does not compile or run this
file even if a developer exports the environment variable.

Guards: the suite hard-fails before any private request unless the keys are
present, sandbox/testnet mode is on, and the resolved base URL is
`https://testnet.binance.vision`. It refuses `ENGINE_EXCHANGE_BASE_URL` and any
production host, because a relay that rewrites the request could turn a testnet
key into a production request. Do not run it with production credentials. The
default verify and CI never set `BINANCE_TESTNET`. There is no MAX live or MAX
testnet suite in this change.

## Current limitations

- Live placement is gated by `TRADING_LIVE_ENABLED` and defaults to off. A live
  bot start while closed pauses it with a clear error and cancels any tracked
  live orders; there is no automatic paper fallback.
- Binance is the only live/testnet venue proven end to end. MAX compiles and is
  covered by fakes but refuses live and testnet credentials in this build.
- Public market data and candle backfill support Binance only. Starting a paper
  bot on another exchange pauses it instead of retrying every tick forever.
- Single live-order cancellation from the UI still uses Laravel's retained
  exchange path; the engine owns stop, kill-switch, and in-band cancellation.
- The runtime owns paper and live orders and fills. It does not write Laravel's
  user acknowledgement activity types.

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
