# Go engine contract

This document is the operator-facing contract for the hybrid Laravel + Go
engine described in
`openspec/changes/adopt-hybrid-go-engine/design.md`. It covers the v1 Redis
Stream protocol, command/event types, delivery and ordering rules, status
ownership, and the heartbeat.

> **Current phase:** the Go engine under `engine/` implements the contract,
> consumer, idempotency, heartbeat, public market data, `/healthz` readiness,
> the tick loop, grid and paper-fill runtime, strategies, risk/exit guards,
> deterministic backtest replay with PostgreSQL persistence, and gated live
> placement/cancellation/reconciliation. Laravel publishes user commands and
> backtest commands, writes only `desired_status`, shows an expired heartbeat
> as a warning, and retains the emergency `CancelBotOrders` path. Live execution
> is off unless `TRADING_LIVE_ENABLED=true`; the first rollout target is Binance
> testnet, and production real-money live use needs an explicit operator yes.

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

The backtest event names are part of the v1 contract but this build does not
publish them; backtest results are read from Postgres. Event emission remains a
later follow-up.

The list is non-exhaustive. This build also emits `engine.command_failed` for
definitive contract rejections and dead-letters; its payload carries
`correlation_id` (the command `id`), `command_id`, `command_type`, `reason`, and
`attempts`, and never an error string or payload contents. Events that complete
a command set `correlation_id` to the command `id`.

## Delivery: at-least-once, ACK, PEL, dead-letter

1. The engine reads `engine.commands` with the `engine` consumer group, and
   `engine.backtests` with the `engine-backtests` group. Backtests use a
   separate stream so a replay cannot delay bot, order, or kill-switch
   commands.
2. It applies the command's durable Postgres effects, writes
   `engine:cmd:{id}`, and only then sends `XACK`. A failed effect or a failed
   idempotency write leaves the entry pending and is retried.
3. On engine start it auto-claims pending entries (PEL) idle for at least
   **30 seconds** and reprocesses them. If `engine:cmd:{id}` is already present,
   the entry is acknowledged with no second effect; otherwise it is retried.
4. After **five failed attempts** the command is written to
   `engine.commands.dlq` (or `engine.backtests.dlq`), an
   `engine.command_failed` event is written without secrets, the id is recorded,
   and the original entry is `XACK`ed so the group is not blocked. Operators can
   inspect or replay the dead-letter stream manually.
5. Laravel event consumers follow the same ACK, PEL auto-claim, and dead-letter
   rules on `engine.events` / `engine.events.dlq`.

### Ordering

Commands are applied in stream order. Commands that share an ordering key are
therefore serial:
- bot commands and `kill_switch` use the envelope `user_id` key when present,
  so a user's kill-switch is serialized against that user's bot commands;
- a command without `user_id` falls back to its `bot_id`.

A later command for the same bot cannot run before an earlier one. v1 consumes
each stream with a single worker, so different users also wait for each other;
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

The Laravel side of this wiring is implemented: start, pause, stop, single-order
cancel, and the kill-switch write `desired_status` and publish their command.
The Go engine executes those commands, including live placement when the gate is
open. Stop and kill-switch use the emergency local cancel only when the
heartbeat is unavailable or the publish fails. Single live-order cancellation
continues through Laravel's retained local exchange path; paper cancellation
goes through `order.cancel`. A closed live gate pauses the bot and records a
risk event; it never converts the bot to paper.

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
Laravel exposes the same freshness check at `GET /health/engine`, returning
`200` when fresh and `503` when missing or expired.
`GET /livez` remains dependency-free.

## Status ownership

| Column | Writer | Rule |
| --- | --- | --- |
| `bots.desired_status` | Laravel | User intent: set on start, pause, stop, and kill-switch; `idle` on create. Added by a Laravel migration and backfilled from the old `status`. |
| `bots.status` | Go engine | Runtime state. The engine applies `desired_status` and must not set running over a pause, stop, or kill-switch. |
| `bots.last_error` | Go engine | Runtime failure text. Laravel does not write it after the ownership migration. |

Laravel now writes only `desired_status` on start, pause, stop, and kill-switch;
it no longer writes `status` or `last_error`, and the PHP tick pipeline is no
longer scheduled. The column exists so the cutover and the Go engine have a
single source of user intent.

## Audit and receipts

For v1, the Redis Stream entries plus `engine:cmd:{id}` are sufficient audit
evidence of accepted, rejected, and completed commands. **No receipt table is
added.** If a future requirement needs durable, queryable command history
independent of Redis retention, a Laravel-owned receipt migration can be added
then; the engine never runs migrations.

## Market data (cached tick price source)

`engine/internal/marketdata` implements the public Binance feed from design §5
without credentials: `<symbol>@trade` plus `<symbol>@bookTicker` streams,
exponential reconnect backoff, trade-id gap and quiet-period detection, a
public REST `24hr` ticker snapshot for recovery, and periodic REST tickers while
the socket cannot connect. A degraded feed emits `marketdata.degraded` (with a
secret-free reason) and recovery emits `marketdata.recovered`.

The feed subscribes to the union of running and starting Binance bots' symbols
plus the optional `ENGINE_MARKET_DATA_SYMBOLS` watchlist. It adds a symbol when
a bot starts, removes it after the last bot using it stops, and drops the
removed symbol's cached quote. When the set becomes empty the socket is closed
and the feed is idle.

Ticks read `marketdata.PriceSource`, not the public REST endpoint directly. A
cached quote no older than `ENGINE_MARKET_DATA_STALE_AFTER` (default
`max(15s, TRADING_TICK_INTERVAL / 2)`) is used as-is. A missing or stale quote
triggers one public REST snapshot; if that also fails, the tick fails instead of
trading on an old price. Only the trade timestamp counts for staleness, so a
fresh book-ticker update cannot make an old last price look current. No market
data path reads API keys or calls a private endpoint.

The feed is **idle unless** `ENGINE_MARKET_DATA_WS_URL` and
`ENGINE_MARKET_DATA_REST_URL` are both set. Local Compose sets the public
Binance URLs and `TRADING_TICK_INTERVAL`; no symbol list is required. Without
the endpoints, ticks still use one public REST snapshot per evaluation.

## Setup plan evaluation

`POST /v1/plan/evaluate` runs the same Go strategy code used by ticks against a
non-persisted setup and returns levels, totals, errors, warnings, and the public
market data supplied by Laravel. It makes no private call and writes no bot,
grid, order, or risk row. Laravel's `POST /bots/preview` and both save requests
call it; if the engine is unreachable, preview and save fail closed and persist
nothing.

Compose does not publish the engine port. Laravel reaches it at
`ENGINE_PLAN_URL=http://engine:8080`. If an operator ever exposes the port
beyond the internal network, set `ENGINE_INTERNAL_TOKEN` in both services; the
endpoint then requires `Authorization: Bearer <token>`. `plan-server` is a
DB/Redis-free subcommand used by the Laravel contract tests:
`/engine plan-server`.

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
`paper_fills.json`. They are now used by the runtime: `main` starts the tick scheduler and grid sync,
and paper fills are persisted by `internal/runtime`. `internal/decimal` matches PHP bcmath at scale 16 (truncating multiply/add,
17-digit divide rounded half-up to 16) and rejects floats.

## Risk gates and credential decryption (phase 5)

`internal/risk` provides the blocking guards used by the paper runtime:

- live gate and start checks (`TRADING_LIVE_ENABLED` defaults false, exact
  confirmation, free quote balance, no live-to-paper fallback);
- position notional, open-order, and sell-inventory reservation checks;
- per-bot and account-wide daily loss pause/cancel with engine-owned baselines;
- protective stop-loss, take-profit, and range exits (pause/cancel only, no
  market flatten, no automatic resume);
- consecutive exchange failure threshold pause/cancel;
- `TRADING_EXCHANGES` creation validation plus Go start/placement blocks.

These packages are proven against every case in `tests/golden/engine/risk.json`,
including the fixture's `direct_insert_current_php` cases that document the
PHP-only gap. `internal/credentials` implements the same AES-256-CBC payload as
Eloquent `encrypted` / `Crypt::encryptString` (HMAC-SHA256 MAC, JSON
`iv`/`value`/`mac`/`tag`, no PHP serialization, current then previous keys) and
is proven against the committed stub ciphertexts in
`tests/golden/engine/credentials_ciphertext.json`. It never reads `APP_KEY` or
`.env`; callers supply the key, and `Redact` / `SafeError` keep it out of logs.

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
