# Bot Parameters

This document explains every parameter a bot has, where it is stored, and how it affects
execution. Parameters come from three layers:

```
+-----------------------------+     +-----------------------------+     +----------------------+
|  Per-bot form fields        |     |  Per-bot runtime columns    |     |  System-wide .env    |
|  (Create/Edit bot)          |     |  (not in the form)          |     |  (all bots)          |
+-----------------------------+     +-----------------------------+     +----------------------+
| name, exchange, symbol      |     | tick_interval_seconds       |     | live gate, exchange  |
| strategy_key, mode          |     | fee_rate                    |     | allowlist, tick,     |
| capital_allocation          |     | base/quote inventory        |     | retries, risk caps   |
| grid + strategy fields      |     | consecutive_failures        |     | sync intervals       |
+-----------------------------+     +-----------------------------+     +----------------------+
```

The flow is: create/edit form -> `StoreBotRequest` / `UpdateBotRequest` ->
`StrategyConfig.configuration` -> Go strategy (`engine/internal/strategy`) ->
risk gates at tick time.

The form also calls the Go engine's internal `POST /v1/plan/evaluate` through
`POST /bots/preview`, which returns the ladder, notional totals, and advisories
without persisting anything. The same Go evaluator runs inside both save
requests, so the preview and the saved setup cannot disagree. If the engine is
unreachable, preview and save both fail closed with a clear error.

---

## 0. Quick setup presets

Quick setup needs only name, exchange, symbol, capital allocation, and center
price. It resolves a preset from `config/bot_presets.php` into concrete advanced
values:

| Preset | Strategy | Levels per side | Spacing | Character |
| --- | --- | --- | --- | --- |
| `balanced` | simple-grid | 6 | 0.4% geometric | Default for ranging markets. |
| `tight` | simple-grid | 10 | 0.2% geometric | Small moves; uses the full default order limit. |
| `wide` | simple-grid | 4 | 1% geometric | Fewer, wider levels for larger swings. |
| `trailing` | trailing-grid | 6 | 0.5% geometric | Re-centers after a 1.5% move. |

Spacing is stored as a fraction (`0.4%` becomes `0.004`). Order quantity is
derived with bcmath strings:

```
order_quantity = capital_allocation * utilization / (levels_per_side * center_price)
```

`utilization` defaults to `0.8`, leaving 20% of the allocation as headroom.
Presets are symbol-agnostic, so the derived quantity still has to clear the
exchange minimums for the chosen pair.

The advanced form is the same explicit field set described below. Switching from
quick to advanced preserves the derived values.

---

## 1. Identity and execution fields

| Field | Validation | What it does |
| --- | --- | --- |
| **name** | required, max 120 chars | Label; also used in the live confirmation phrase. |
| **exchange** | must be in `TRADING_EXCHANGES` (default `binance`) | Which CCXT exchange the bot talks to. |
| **symbol** | regex `BASE/QUOTE` (e.g. `BTC/USDT`) | Trading pair. |
| **strategy_key** | one of `simple-grid`, `trailing-grid`, `martingale-grid`, `dynamic-grid` | Selects the strategy class. Cannot be changed after creation. |
| **mode** | `paper` (default) or `live` | Paper = simulated fills from public prices. Live = real orders, only if `TRADING_LIVE_ENABLED=true`. |
| **live_confirmation** | must exactly equal `<name> on <exchange>` | Extra guard for live creation. Error text: "Type ... to confirm live trading." |
| **capital_allocation** | decimal > 0, quote asset | Seeds `quote_inventory` at creation. Not a hard spending cap; the real exposure cap is the risk limit. For live starts it must be covered by the credential's free quote balance. |
| **fee_rate** | 0-0.1 (0%-10%) | Fallback fee charged per fill when the exchange does not report one. Paper default is `0`; live fills use exchange-reported fees when available. |

Live mode additionally requires a saved credential for that exchange and passes
`LiveModeGuard` / balance checks at start. There is no silent live-to-paper fallback.

---

## 2. Shared grid geometry (all strategies)

These fields define the ladder:

| Field | Constraint | Meaning |
| --- | --- | --- |
| **center_price** | decimal > 0 | Anchor price. Buys sit below it, sells above it. No order is placed at the center. |
| **level_count** | integer 1-50 | Levels per side. Total orders = `2 x level_count`. Stored as `levels_per_side`. |
| **spacing_type** | `arithmetic` or `geometric` | How gaps are measured. |
| **spacing_value** | decimal > 0; for geometric must be < 1 | Arithmetic: absolute quote gap. Geometric: fractional gap, e.g. `0.01` = 1%. |
| **order_quantity** | decimal > 0 (base asset) | Quantity per order. Order notional = `quantity x price`. |

Example: center `50000`, arithmetic spacing `100`, 3 levels per side:

```
        SELL  50300   ^  sell side (needs base inventory)
        SELL  50200   |
        SELL  50100   |
        ------ 50000  center (no order)
        BUY   49900   |
        BUY   49800   |
        BUY   49700   v  buy side (uses quote inventory)
```

How levels are built (`engine/internal/strategy/grid.go`):

- Arithmetic: `center +/- spacing * i`
- Geometric: `center * (1+spacing)^i` above, `center / (1+spacing)^i` below
- Prices are sorted and assigned sequence numbers; duplicate and non-positive prices are
  rejected before the bot is saved.
- A geometric spacing of `1` or more is rejected up front. It does not keep the ladder
  proportional: buy levels collapse toward zero while sell levels grow without bound.

Hard ceilings from the risk layer (defaults, editable at account level):

- `max_position_notional = 1000` - projected `abs(base_inventory) * price + open buy notionals
  +/- new order` must stay under this.
- `max_open_orders = 20` - a typical 6-per-side grid = 12 orders, so it fits; 11+ per side
  starts getting blocked.
- Sells are blocked with `risk.insufficient_inventory` until a buy fills and funds them. This is
  normal deferral, not an error - a quote-only bot places buys first.
- Daily loss limit = 100 quote/day (UTC), enforced per bot and account-wide.

### Before saving: errors versus advisories

The preview (`POST /bots/preview`) and both save requests call the same Go
engine evaluation endpoint:

- **Errors stop saving.** These are setups that cannot run: geometric spacing >= 1,
  non-positive or duplicate level prices, and an order below the exchange minimum
  amount or notional when market data is available. Missing market data is not an
  error.
- **Advisories never stop saving.** These are setups that can run but deserve a
  look: more orders than `max_open_orders`, buy notional above
  `max_position_notional`, a center price more than 50% away from the market, and
  sells that no inventory funds yet. The risk layer still rejects individual
  orders at tick time, so an advisory is not a promise that every level fills.
- Risk limits are read without creating a row, so previewing never changes account
  settings.

---

## 3. Strategy-specific fields

### `trailing-grid`

| Field | Constraint | Behavior |
| --- | --- | --- |
| **trailing_distance** | decimal > 0, required | Absolute quote distance. When `abs(market - center) >= distance`, the bot recenters on the current price; if the new levels differ, runtime cancels the existing ladder, stops the old grid, and places the new one. |

It is a recentering trigger, not a stop-loss. Note it is an absolute price gap, not a percentage.

### `martingale-grid`

| Field | Constraint | Behavior |
| --- | --- | --- |
| **size_multiplier** | > 1, default `2` | After each buy entry fill, next buy entry size = `current * multiplier`. |
| **max_order_quantity** | required, >= base quantity | Hard ceiling on the escalated size. |
| **max_increases** | integer >= 0, default `3` | Number of escalation steps before the cap locks. `0` effectively disables escalation. |

On every buy entry fill it ratchets the buy size up until either the quantity cap or increase
count is hit, then records a `martingale.cap_reached` event. Every sell fill, including a sell of
starting inventory, resets the size to the base quantity, the increase count to zero, and the cap
flag to false. This is the most
dangerous profile - size grows during a losing sequence by design, which is why both caps are
mandatory.

Creation and updates reject a maximum below the base order quantity, a multiplier of `1` or less,
or a negative increase count. The engine repeats those checks at initialization and on every tick
of an existing bot, so a stale invalid configuration fails before any order is placed.

### `dynamic-grid`

| Field | Constraint | Behavior |
| --- | --- | --- |
| **min_spacing** | decimal > 0, required | Lower clamp on computed spacing. |
| **max_spacing** | decimal > 0, required, >= min | Upper clamp. |
| **volatility_multiplier** | > 0, default `1` | Scales measured volatility into spacing. |
| **recalculation_interval_seconds** | >= 1, default `60` | How often spacing is recomputed. |
| **spacing_hysteresis_percent** | 0-90, default `20` | Adopt a new spacing only when it differs from the current spacing by at least this percentage. `0` adopts every candidate. |

Spacing formula (`DynamicGridStrategy::calculateSpacing`):

```
volatility = (max - min) / midpoint        over recent prices (last 30 ticks)
candidate  = clamp(center * volatility * multiplier, min_spacing, max_spacing)
```

`spacing_hysteresis_percent` is a percentage, not a fraction: `20` means 20 percent, not `0.20`.
The comparison is inclusive, so a candidate exactly at the threshold is adopted. A high value can
hold spacing away from the configured clamps; values near the default are usually a better fit.

Flow of one recalculation:

```
tick due? --no--> keep current grid
   |
  yes
   v
compute volatility from bots.settings.recent_prices (30 prices)
   v
candidate = clamp(...)
   v
candidate differs from current spacing by less than threshold?
   | yes
   +--> keep current spacing and ladder, wait another interval
   |
   no
   v
replace the ladder: cancel all open orders, stop the old grid, create the new levels and orders
```

Skipping still advances the recalculation timestamp and count. `spacing_hysteresis_percent: 0`
restores the pre-hysteresis behavior of adopting every candidate. Existing bots without the key
use the default `20`; the first saved edit adds it.

Important: dynamic grid never moves the center - unlike trailing, it only changes spacing around
a fixed center. It always uses arithmetic spacing, so the create and edit forms hide the
`spacing_type` and `spacing_value` fields for this strategy and do not require them.

When the threshold is crossed, runtime replaces the entire ladder because the new prices differ.
It does not retain individual orders that happen to fall on a matching new price.

To measure the effect after deployment, compare the engine's `bot tick completed` records for
the same dynamic bots over a comparable window. `grids_replaced` and `orders_canceled` should
fall relative to `orders_placed` and `paper_fills`; if fills fall too, the threshold may be too
high or the fixed center may be stale.

---

## 4. Protective exits (per-bot, optional)

These four nullable fields live on the bot row and are edited from the bot detail page, not the
create/edit form. Blank means the rule is disabled.

| Field | Validation | Effect |
| --- | --- | --- |
| **stop_loss_percent** | null or decimal `0 < x < 100`, up to 4 decimal places | Pauses when the mark is at or below `average_entry_price * (1 - x / 100)`. Requires positive base inventory and average entry. |
| **take_profit_percent** | null or decimal `0 < x < 100`, up to 4 decimal places | Pauses when the mark is at or above `average_entry_price * (1 + x / 100)`. Requires positive base inventory and average entry. |
| **range_low** | null or decimal `> 0`, up to 8 decimal places | Pauses when the mark is at or below this price. Works without inventory. |
| **range_high** | null or decimal `> 0`, up to 8 decimal places | Pauses when the mark is at or above this price. Works without inventory. `range_low` must be below `range_high` when both are set. |

Rules are evaluated every running tick after the mark price is stored and before the daily-loss
check and strategy logic. Precedence is stop-loss, take-profit, range exit; only one trigger is
recorded per tick. A trigger pauses and cancels open orders but never sells inventory. There is no
liquidation, market order, or automatic resume. The exit guard remains editable while the bot is
running, paused, or stopped. Strategy fields are editable while idle or stopped, or while a paper
bot is running after explicit confirmation; live running and paused bots are read-only.

---

## 5. Runtime parameters (not in the form)

These live in `.env`, `config/trading.php`, and `config/risk.php`:

| Env var | Default | Effect |
| --- | --- | --- |
| `TRADING_LIVE_ENABLED` | `false` | Master gate for all real orders. |
| `TRADING_DEFAULT_EXCHANGE` | `binance` | Fallback exchange when none supplied. |
| `TRADING_EXCHANGES` | `binance` | Comma-separated allowlist for the exchange dropdown. |
| `TRADING_PAPER_FEE_RATE` | `0` | Default paper fee fraction (prefills the create form and seeds `fee_rate` when the field is omitted). |
| `TRADING_MARKET_DATA_CACHE_SECONDS` | `60` | How long preview market data is cached per exchange and symbol. |
| `TRADING_TICK_INTERVAL` | `10` | Copied into each new bot's `tick_interval_seconds`. The Go engine evaluates each bot only when its interval has elapsed. |
| `TRADING_MAX_CONSECUTIVE_FAILURES` | `3` | Documented threshold; the current Go runtime hardcodes 3 and does not read this variable yet. |
| `TRADING_RECONCILIATION_TOLERANCE` | `0.0001` | Absolute quantity tolerance when comparing local vs exchange balances. |
| `TRADING_STALE_TICK_SECONDS` | `180` | Documented floor; the current Go runtime hardcodes max(180s, tick interval + 30s). |
| `TRADING_HEALTH_CHECK_SECONDS` | `30` | The engine's health-check cadence. |
| `TRADING_ORDER_SYNC_SECONDS` | `5` | The engine's exchange open-order sync cadence. |
| `TRADING_BALANCE_SYNC_SECONDS` | `60` | The engine's live inventory sync cadence (skipped when multiple live bots share a credential). |
| `TRADING_EXCHANGE_TIMEOUT_MS` | `15000` | CCXT request timeout. |
| `TRADING_EXCHANGE_RATE_LIMIT_MS` | `1000` | Minimum gap between exchange calls. |
| `TRADING_EXCHANGE_MAX_RETRIES` | `3` | Retry attempts on transient exchange errors. |
| `TRADING_EXCHANGE_RETRY_DELAY_MS` | `250` | Base retry backoff. |
| `TRADING_EXCHANGE_SANDBOX` | `false` | Use exchange testnet. |
| `RISK_MAX_POSITION_NOTIONAL` | `1000` | Default per-bot quote exposure cap. |
| `RISK_MAX_OPEN_ORDERS` | `20` | Default open-order cap. |
| `RISK_DAILY_LOSS_LIMIT` | `100` | Quote loss allowed per UTC day. |

Risk limits resolve in this order: bot-scope row -> user-scope row -> config defaults. The UI
edits the user-scope row; new bots do not get their own row, so account-wide limits apply
immediately.

---

## 6. Editing and lifecycle notes

- Idle and stopped bots are editable. A running paper bot is editable after an explicit
  confirmation; live running and paused bots are read-only.
- Protective exit rules are separate from the strategy form and remain editable in every bot state.
- The edit form can change name, `capital_allocation`, `fee_rate`, `tick_interval_seconds`, and
  strategy configuration - not exchange, symbol, strategy, or mode. The tick interval accepts 1-3600
  seconds.
- A running paper edit shows a field diff and ladder preview before saving. A confirmed structural
  or capital change sets `reconcile_pending`; the engine cancels open orders, clears strategy state,
  and rebuilds the ladder on the next tick. Name-only, fee-only, and interval-only changes do not
  themselves cancel orders or rebuild the ladder; an overdue bot can still be paused by the
  stale-tick check, which cancels orders.
- An idle or stopped structural change sets a pending strategy reset instead. The update retires
  the active grid, clears strategy state, and the new config takes effect on the next start.
  Strategies copy structural values into their state on `initialize()`.
- Pause keeps open orders; Stop cancels them.
- A restart after a config change re-initializes the grid; the bot does not resume the old ladder.

---

## Practical example

A paper bot: `BTC/USDT`, simple-grid, center `50000`, 6 levels/side, arithmetic spacing `100`,
quantity `0.001`, capital `1000`.

- 6 buys at 49,900 to 49,400 (~$299 notional total) and 6 sells at 50,100 to 50,600.
- Initial quote inventory 1000 funds buys; base inventory 0 means all 6 sells are blocked by
  `risk.insufficient_inventory` until buys fill. Each buy fill funds the adjacent sell. That
  sell can sit at a level whose ladder plan is still `buy`; the plan side is the center-based
  label, while the open order's side controls fills and inventory.
- All 12 orders fit under the 20-order default; buy notional fits under 1000.
- Tick interval 10s, fees 0.
