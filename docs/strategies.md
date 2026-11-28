# Strategies

The bot engine is strategy-agnostic. A strategy decides what grid to build and
which orders to request; the engine owns persistence, exchange calls, risk
checks, and order/fill bookkeeping. Strategies never read the database and never
call an exchange.

**Go is the only evaluator.** The four built-in strategies live in
`engine/internal/strategy`. Laravel keeps only a key catalog
(`App\Domain\Strategy\StrategyRegistry`) for form validation and backtest
parameter sweeps. There are no PHP strategy implementations and no PHP
`onTick` path: preview, create/update validation, and runtime ticks all use the
same Go code.

The preview path is:

```text
resources/js create/edit form
  -> POST /bots/preview
  -> App\Services\Engine\GridPlanClient
  -> Go engine POST /v1/plan/evaluate
  -> strategy OnTick with an empty state
```

`StoreBotRequest` and `UpdateBotRequest` call the same client during validation,
so the ladder a user previews is the ladder the engine will run. If the engine
is unreachable, preview and save both fail with a clear error and persist
nothing. The endpoint is internal-only: Compose does not publish the engine
port, and `ENGINE_INTERNAL_TOKEN` is required if it is ever exposed beyond the
service network.

For the full list of bot fields, their validation rules, and their runtime
effects, see [Bot parameters](bot-parameters.md).

## Runtime contract

The Go interface lives in `engine/internal/strategy/types.go`:

```go
type Strategy interface {
    Key() string
    Initialize(input Input) (Decision, error)
    OnTick(input Input) (Decision, error)
    OnFill(input Input, fill FillEvent) (Decision, error)
    OnCancel(input Input, cancel CancelEvent) (Decision, error)
    ShouldStop(input Input) bool
    GetGridLevels(input Input) ([]GridLevelPlan, error)
}
```

`Input` is immutable and contains:

- `Market` — symbol, current price, optional bid/ask, recent prices, timestamp.
- `Bot` — bot id, base/quote inventory, average entry price, realized P&L,
  capital, open orders.
- `Config` — the strategy configuration stored on the bot.
- `State` — the versioned strategy state resumed from the database.

`Decision` is also immutable. It carries the next state, grid levels, orders to
place, an optional stop flag, events, and client order IDs to cancel.

## State rules

- `Initialize` must be idempotent and return the existing state unchanged when
  the state is not new.
- Return the next state from every hook through `Decision`.
- State is persisted as JSON with `{ "version": 1, "data": { ... } }`. An
  unsupported version pauses the bot rather than guessing.
- State must be JSON-serializable and free of floats. Use decimal strings for
  money and quantities.

## Built-in strategies

| Key | Purpose | Required configuration |
| --- | --- | --- |
| `simple-grid` | Static arithmetic or geometric ladder | `center_price`, `levels_per_side` or `level_count`, `spacing_type`, `spacing_value`, `order_quantity` |
| `trailing-grid` | Recenters when price moves beyond a distance | Simple grid keys plus `trailing_distance` |
| `martingale-grid` | Increases buy size after entry fills, resets after sells, with hard caps | Simple grid keys plus `size_multiplier`, `max_order_quantity`, `max_increases` |
| `dynamic-grid` | Sets spacing from recent volatility | `levels_per_side`, `order_quantity`, `min_spacing`, `max_spacing`, optional `volatility_multiplier`, `initial_spacing`, `recalculation_interval_seconds`, `spacing_hysteresis_percent` |

Martingale requires `max_order_quantity` to be at least the base order quantity
(the submitted `order_quantity`), `size_multiplier` to be greater than `1`, and
`max_increases` to be zero or greater. Creation, updates, engine initialization,
and every tick of an existing bot reject values outside those bounds before any
order is placed.
Only buy entry fills increase size. Every sell fill resets the current quantity
to the base, the increase count to zero, and the cap flag to false.

Trailing-grid and martingale-grid accept only `arithmetic` or `geometric`
spacing. Dynamic spacing is reserved for the dynamic grid, which derives its
spacing from recent volatility and ignores `spacing_type`.

Dynamic-grid keeps replenishing its ladder between spacing recalculations. On a
tick that is not due for a recalculation, it retains the persisted spacing and
emits an order for every armed level that has no open order. This restores
orders after cancellation, rejection, a position-limit skip, a pause/resume, or
a grid replacement instead of waiting for the next recalculation. Prices shared
by the old and new ladders also receive orders after the replacement. Stale
disarmed-level entries are pruned when a recalculated or recentered ladder no
longer contains their paired price.

Canceling a paired take-profit order does not re-arm the entry level that
produced it. The inventory stays in the portfolio and can still exit through a
native sell level as price rises. A pause/resume or ladder replacement can
therefore leave fewer take-profit orders than open inventory lots; the strategy
does not track inventory lots or synthesize replacement sells, and the next
opposite fill or ladder replacement re-arms levels normally.

Dynamic-grid accepts an optional `initial_spacing` value. It is used only until
two recent prices are available, is clamped to `min_spacing` and `max_spacing`
like every other candidate, and falls back to `min_spacing` when unset.

## Adding a strategy

1. Implement the Go `Strategy` interface in `engine/internal/strategy`.
2. Register the key in the Go factory (`NewStrategy`).
3. Add the key to `StrategyRegistry::KEYS` for Laravel form validation and
   backtest sweeps.
4. Add Go fixture/parity tests. There is no PHP implementation to mirror.

The key is stored in `strategy_configs.strategy_key`, so renaming it requires a
data migration.
