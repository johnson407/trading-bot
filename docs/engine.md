# Go engine contract

This document is the operator-facing contract for the hybrid Laravel + Go
engine described in
`openspec/changes/adopt-hybrid-go-engine/design.md`. It covers the v1 Redis
Stream protocol, command/event types, delivery and ordering rules, status
ownership, and the heartbeat.

> **Current phase:** the Go engine under `engine/` implements the contract,
> consumer, idempotency, heartbeat, public market data, `/healthz` readiness,
> and the phase-4.2/4.3/4.4 tick-loop, grid, and paper-fill libraries. The tick
> scheduler, grid sync, and paper-fill selection are **not wired into the
> process**: this build does **not** run ticks, strategies, orders, or
> backtests, and it makes no exchange calls.
> Laravel is still the trading runtime until the later apply phases. Any
> command received by this build is rejected with an `engine.command_failed`
> event and never executed.

## Streams

Redis Streams carry commands and results. Redis and Postgres are shared by both
processes; the stream entries are JSON envelopes stored in the field
`envelope`.

| Stream | Writer | Consumer group | Purpose |
| --- | --- | --- | --- |
| `engine.commands` | Laravel | `engine` | Start, pause, stop, cancel, kill-switch, and backtest commands |
| `engine.events` | Go engine | `laravel` | Status, failure, kill-switch, and backtest events |
| `engine.commands.dlq` | Go engine | operators | Poison commands after five failed attempts |
| `engine.events.dlq` | Laravel event consumer | operators | Poison events after five failed attempts |

v1 runs **one** engine instance. A second instance must not take over the
command group without a lock; that is a later change. A single consumer applies
entries in stream order, so commands that share an ordering key (see below)
cannot overtake each other.

## Envelope

```json
{
  "v": 1,
  "id": "01jcexxxxxxxxxxxxxxxxxxxxx",
  "type": "bot.start",
  "ts": "2026-11-23T05:00:00Z",
  "user_id": 1,
  "bot_id": 208,
  "payload": {}
}
```

- `v` is the integer schema version. This build processes `v = 1`.
- `id` is the producer-generated idempotency key (ULID or UUID). It must be
  unique per logical command.
- `ts` is UTC RFC 3339.
- `user_id` and `bot_id` are envelope fields. The engine also accepts them from
  `payload` when the envelope omits them. Bot commands must carry `user_id` so
  a user's `kill_switch` can be serialized against them.
- Unknown fields in a v1 envelope or payload are ignored.
- Payloads must never contain credentials, `APP_KEY`, raw authorization
  headers, or decrypted secrets.

Producers write the JSON to a stream field named `envelope`. The engine also
accepts `data` or `payload` as a compatibility fallback, but new producers
should use `envelope`.

### Version handling and idempotency

- A higher `v` is decoded but **not executed**. The engine logs it without
  payload/secrets, writes an `engine.command_failed` event with reason
  `unsupported_version`, records the id, and acknowledges the entry. Operators
  must upgrade the engine before publishing that version.
- A duplicate `id` is acknowledged with **no second effect**. The engine stores
  `engine:cmd:{id}` in Redis after durable effects (or a definitive rejection)
  with a TTL of at least seven days.
- A decoded v1 command whose required identifiers are missing is a definitive
  rejection (`invalid_command`): it is acknowledged with a failed event and no
  execution.
- An envelope that cannot be decoded (malformed JSON) is poison. It is retried
  like any transient failure and dead-lettered after five attempts.
- A transient processing failure is retried. After five failed attempts the
  entry is dead-lettered.

## Command types (v1)

| `type` | Required identifiers | Effect |
| --- | --- | --- |
| `bot.start` | `bot_id`, `user_id` | If startable, set runtime running and begin ticks; a live start also enforces the live gate and balance. |
| `bot.pause` | `bot_id`, `user_id` | Stop new placements; leave open orders and strategy state. |
| `bot.stop` | `bot_id`, `user_id` | Cancel open orders; mark stopped. |
| `order.cancel` | `bot_id`, `user_id`, `payload.order_id` | Cancel one order. |
| `kill_switch` | `user_id` | Pause every bot for the user and cancel their open orders immediately. |
| `backtest.run` | `payload.backtest_run_id` | Execute a queued backtest run. |
| `backtest.sweep` | `payload.backtest_sweep_id` | Execute a queued sweep. |

## Event types (v1)

`engine.heartbeat`, `bot.started`, `bot.paused`, `bot.stopped`,
`bot.tick_completed`, `bot.error`, `kill_switch.completed`,
`backtest.progress`, `backtest.completed`, `backtest.failed`.

The list is non-exhaustive. This build also emits `engine.command_failed` for
definitive contract rejections and dead-letters; its payload carries
`correlation_id` (the command `id`), `command_id`, `command_type`, `reason`, and
`attempts`, and never an error string or payload contents. Events that complete
a command set `correlation_id` to the command `id`.

## Delivery: at-least-once, ACK, PEL, dead-letter

1. The engine reads `engine.commands` with the `engine` consumer group.
2. It applies the command's durable Postgres effects, writes
   `engine:cmd:{id}`, and only then sends `XACK`. A failed effect or a failed
   idempotency write leaves the entry pending and is retried.
3. On engine start it auto-claims pending entries (PEL) idle for at least
   **30 seconds** and reprocesses them. If `engine:cmd:{id}` is already present,
   the entry is acknowledged with no second effect; otherwise it is retried.
4. After **five failed attempts** the command is written to
   `engine.commands.dlq`, an `engine.command_failed` event is written without
   secrets, the id is recorded, and the original entry is `XACK`ed so the group
   is not blocked. Operators can inspect or replay the dead-letter stream
   manually.
5. Laravel event consumers follow the same ACK, PEL auto-claim, and dead-letter
   rules on `engine.events` / `engine.events.dlq`.

### Ordering

Commands are applied in stream order. Commands that share an ordering key are
therefore serial:

- bot commands and `kill_switch` use the envelope `user_id` key when present,
  so a user's kill-switch is serialized against that user's bot commands;
- a command without `user_id` falls back to its `bot_id`.

A later command for the same bot cannot run before an earlier one. v1 consumes
the stream with a single worker, so different users also wait for each other;
the ordering key above is the contract a later concurrent executor must honour.
That optimization can run different keys in parallel without changing this
serialization rule.

## Kill-switch immediacy

Laravel performs one user action when the operator activates the kill-switch:

1. writes `desired_status = paused` for the user's bots;
2. attempts `XADD` of `kill_switch` to `engine.commands`.

When the heartbeat is fresh and `XADD` succeeds, the engine reads the command
as soon as it is available and cancels open orders without waiting for the next
tick. Independently, the engine refreshes `desired_status` from Postgres at
least every second and whenever it would place or cancel, so a paused or
stopped desired row is honoured even if the Stream is down.

**Emergency path (user-triggered only):** if Redis `XADD` fails, or the engine
heartbeat is already missing or expired, Laravel **shall**, in that same action,
cancel open orders on the user's **live** bots via `CancelBotOrders` /
`CancelOrder`. This path is not used when the heartbeat is fresh and `XADD`
succeeds. Laravel still does not write `status` or `last_error`. For paper bots,
writing `desired_status` is enough while the engine is down. Stop of a live bot
uses the same emergency cancel under the same conditions. `CancelBotOrders` is
retained after the PHP engine is deleted.

The Go engine's phase-2 command processor does not place or cancel orders yet;
the wiring above belongs to the later Laravel/engine phases.

## Heartbeat

The engine `SET`s `engine:heartbeat` with TTL **10 seconds** and refreshes it at
least every **5 seconds**:

```json
{"ts":"2026-11-23T05:00:00Z","revision":42,"running_bots":3}
```

- `ts` is UTC RFC 3339.
- `revision` increases on each write by that process.
- `running_bots` is the number of running bots (zero until the tick loop lands).
- No credentials or secrets are included.

Laravel health and the dashboard treat a missing or expired key as **engine
unavailable** and show a warning. An expired heartbeat is a **warning only**:
it must **not** automatically pause bots, cancel orders, or activate the
kill-switch. While the engine is down, live-order cancels happen only when the
operator activates the kill-switch or stops a live bot (the emergency path
above).

`GET /healthz` includes a `heartbeat` readiness check that reads this key and
accepts a timestamp no older than the TTL. It never calls an exchange.
`GET /livez` remains dependency-free.

## Status ownership

| Column | Writer | Rule |
| --- | --- | --- |
| `bots.desired_status` | Laravel | User intent: set on start, pause, stop, and kill-switch; `idle` on create. Added by a Laravel migration and backfilled from the old `status`. |
| `bots.status` | Go engine | Runtime state. The engine applies `desired_status` and must not set running over a pause, stop, or kill-switch. |
| `bots.last_error` | Go engine | Runtime failure text. Laravel does not write it after the ownership migration. |

Until the later Laravel wiring phase, the existing start/pause/stop actions
still write `status` and the PHP tick pipeline still runs. The column exists so
the cutover and the Go engine have a single source of user intent.

## Audit and receipts

For v1, the Redis Stream entries plus `engine:cmd:{id}` are sufficient audit
evidence of accepted, rejected, and completed commands. **No receipt table is
added.** If a future requirement needs durable, queryable command history
independent of Redis retention, a Laravel-owned receipt migration can be added
then; the engine never runs migrations.

## Market data (phase 3)

`engine/internal/marketdata` implements the public Binance feed from design §5
without credentials: `<symbol>@trade` plus `<symbol>@bookTicker` streams,
exponential reconnect backoff, trade-id gap and quiet-period detection, a
public REST `24hr` ticker snapshot for recovery, and periodic REST tickers while
the socket cannot connect. A degraded feed emits `marketdata.degraded` (with a
secret-free reason) and recovery emits `marketdata.recovered`.

The feed is **idle unless** `ENGINE_MARKET_DATA_WS_URL` and
`ENGINE_MARKET_DATA_REST_URL` are both set and `ENGINE_MARKET_DATA_SYMBOLS` is
non-empty. Local Compose sets none of them, so the engine opens no exchange
connection. Phase 4 will supply running-bot symbols to `marketdata.Feed.Run`.

## PHP golden engine fixtures (phase 4 capture)

Before any PHP engine code is removed, task 4.1 captures deterministic golden
fixtures from the current PHP tick pipeline, grid sync, paper fills, and the
four strategies. They live in `tests/golden/engine/` and are verified by
`tests/Feature/GoldenEngineFixturesTest.php`. Later phase-4 tasks consume them
for Go parity tests; the fixtures do not change runtime behaviour.

See [`../tests/golden/README.md`](../tests/golden/README.md) for the fixture
layout, coverage mapping, determinism rules (fixed clock, fixed paper fee,
`Decimal` scale 16), and the exact regeneration command.

`internal/tick`, `internal/grid`, and `internal/paperfill` are pure libraries
proven against `tick_loop.json`, `grid_generation.json`, `grid_sync.json`, and
`paper_fills.json`. They are not started by `main`; the tick scheduler needs a
PostgreSQL bot source and the grid sync and paper fills need a persistence layer
before later phases can wire them in.
`internal/decimal` matches PHP bcmath at scale 16 (truncating multiply/add,
17-digit divide rounded half-up to 16) and rejects floats.

## Local verification

The repository root is mounted so Go tests can read the committed fixtures:

```bash
cd engine
make test

# equivalent from the repository root
docker run --rm -v "$PWD:/src" -w /src/engine \
  -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./...
```

The Go tests use `miniredis`, fake WebSocket servers, and `httptest`. They do
not call an exchange, need no credentials, and do not require the Compose Redis
or a live Postgres. See `engine/README.md` for the current process layout and
configuration.
