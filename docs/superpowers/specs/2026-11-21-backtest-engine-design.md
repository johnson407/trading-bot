# Backtest & Replay Engine — Design

Date: 2026-11-21
Status: Superseded by `openspec/changes/backtest-engine/design.md` after review; kept as design rationale.
Scope: one symbol per run, launched from an existing bot

## Review resolutions applied to the OpenSpec design

- Fills consider only orders open before the current candle's placements; orders
  placed during the candle are eligible next candle.
- The first candle runs `initialize()` then `onTick()`; initial grid creation is
  not a grid replacement.
- Multiple fills on one candle are processed sequentially through `onFill` before
  the next fill.
- Buys are rejected when notional plus fee exceeds free quote inventory.
- `MarketSnapshot` uses the candle close time.
- Candle coverage must be contiguous and closed; interior gaps are fetched or the
  run fails, and the forming candle is excluded.

## 1. Intent

Let a user take an existing bot's configuration, replay it against historical
public candles, and review the outcome in the dashboard. The purpose is
**directional evidence for comparing configurations** before deploying capital,
not tick-exact prediction of live P&L. Runs are saved so two or more can be
compared side by side.

This is the top recommendation from `docs/next-features.md`.

## 2. Goals

- Start a run from any bot the user owns; snapshot its configuration at creation.
- Fetch historical OHLCV from the bot's exchange through CCXT public endpoints.
- Simulate the bot's strategy with a simplified, documented fill model.
- Persist the run, its config snapshot, metrics, equity curve, and simulated fills.
- Provide dashboard list, create, detail, and compare pages.
- Run asynchronously on the existing queue/Horizon stack with visible status.
- Reuse `StrategyInterface`, the `FeeResolver` fee rule, and the
  `InventoryLedger` average-cost/P&L formulas.
- Keep the live `BotTickPipeline` and all production trading behavior untouched.

## 3. Non-goals (v1)

- No tick-level or order-book simulation; no slippage model; no partial fills.
- No parameter sweep, optimization, or walk-forward analysis.
- No standalone backtest config form; runs start from a bot only.
- No overrides of the snapshotted configuration.
- No CSV import or third-party data providers.
- No multi-symbol or portfolio-level runs.
- No changes to live trading, paper trading, risk limits, or order execution.
- No public API; the dashboard is the only surface.
- No automatic deployment of a backtest result to a live bot.

## 4. Anti-goals

The feature fails if any of these become true:

- Results are systematically optimistic enough to encourage a bad deployment.
- A backtest writes bot state, activity, risk events, orders, or fills into the
  live tables.
- The live tick path changes behavior or slows down.
- A user can see another user's runs or another user's bot configuration.
- A run blocks the queue worker for an unbounded time or exhausts memory.

## 5. Constraints from the existing codebase

- Strategies are pure: `App\Domain\Strategy\Contracts\StrategyInterface` receives
  immutable `StrategyInput` and returns `StrategyDecision`. This is the reuse seam.
- `BotTickPipeline` is Eloquent-, queue-, and factory-coupled. It is not reused.
- `ExchangeClient` is order-oriented and has no candle method. Historical data gets
  a separate provider contract, implemented with CCXT public calls.
- Money and quantities are exact decimal strings (`App\Domain\Support\Decimal`).
  No floats in domain or persistence.
- The bot's configured `fee_rate` is a fraction of notional, applied like
  `PaperExchangeClient::qualifyingFills`.
- `InventoryLedger` is the reference for average cost and realized P&L; the test
  price is that its formulas match in-memory bookkeeping.
- Stack: PHP 8.4, Laravel 12, PostgreSQL, Redis/Horizon, Inertia + Vue 3.

## 6. Approaches considered

### A. Replay through the real pipeline

Create a sandbox bot and drive `BotTickPipeline` with a candle-fed client.

- Pro: parity by construction.
- Con: Eloquent per tick, activity/risk row writes, cleanup/isolation, and roughly
  500k ORM ticks for one year of 1-minute data. Isolation becomes its own project.

### B. Dedicated in-memory engine, shared strategies — **chosen**

A `BacktestEngine` walks candles, builds `StrategyInput` from in-memory state, and
calls the same strategies. Only the run header, summary, fills, and a downsampled
equity curve are persisted.

- Pro: fast, side-effect free, unit-testable, leaves production untouched.
- Con: a second, simplified fill/portfolio path that can drift from live behavior.
- Mitigation: mirror the exact fee and P&L formulas; add a parity test against
  `PaperExchangeClient` and `InventoryLedger` on a fixed candle sequence.

### C. Extract a shared simulation kernel

Refactor `BotTickPipeline` into a side-effect-free core used by live and backtest.

- Pro: best long-term parity.
- Con: refactors the battle-tested production path before there is a baseline;
  every live regression becomes a backtest bug.

**Decision:** B, per the human partner, because the result only needs to be
directionally right and comparison is the primary use.

## 7. Architecture

### 7.1 Components

| Component | Responsibility |
| --- | --- |
| `App\Domain\Backtest\Contracts\CandleProvider` | Fetch OHLCV for exchange/symbol/timeframe/range. |
| `App\Services\Exchange\CcxtCandleProvider` | CCXT `fetch_ohlcv`, pagination, retry, rate limit. Public data only. |
| `App\Domain\Backtest\CandleStore` | Read/write the `market_candles` cache; detect missing ranges. |
| `App\Jobs\RunBacktest` | Queue entry point; status transitions, progress updates, failure capture. |
| `App\Domain\Backtest\BacktestEngine` | Pure simulation loop over a configuration and a candle iterable. |
| `App\Domain\Backtest\InMemoryPortfolio` | Quote/base inventory, average entry, realized P&L, fees. |
| `App\Domain\Backtest\SimulatedOrderBook` | Open orders, fills, cancellations, deterministic ids. |
| `App\Domain\Backtest\BacktestRiskGuard` | Exposure, open-order, and daily-loss checks. |
| `App\Domain\Backtest\MetricsCalculator` | Summary metrics and drawdown from the equity series. |
| `App\Models\BacktestRun`, `BacktestFill` | Persistence. |
| `App\Http\Controllers\BacktestController` | Inertia pages, create/compare/destroy. |
| `resources/js/Pages/Backtests/*` | Index, create, show, compare. |

The engine is a pure domain service. It receives a `BacktestConfiguration` value
object and an iterable of `Candle` value objects, and returns a `BacktestResult`.
It does not read Eloquent or call exchanges, so unit tests need no database.

### 7.2 Data model

`market_candles`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `exchange` | string | |
| `symbol` | string | |
| `timeframe` | string | `1m`, `5m`, `15m`, `1h`, `4h`, `1d` |
| `open_time` | timestamp, UTC | start of the candle |
| `open`, `high`, `low`, `close`, `volume` | numeric(24,12) | strings in PHP |
| unique | (`exchange`, `symbol`, `timeframe`, `open_time`) | idempotent upsert |

`backtest_runs`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `user_id` | FK | owner |
| `bot_id` | FK nullable | `nullOnDelete`; the snapshot survives bot deletion |
| `name` | string | defaults to bot name plus range |
| `exchange`, `symbol`, `strategy_key` | string | denormalized for listing |
| `timeframe` | string | |
| `start_at`, `end_at` | timestamp, UTC | closed/open semantics documented on the form |
| `capital_allocation`, `fee_rate` | numeric | snapshot |
| `config_snapshot` | jsonb | immutable bot, strategy, risk, and period snapshot |
| `status` | string | `pending`, `running`, `completed`, `failed` |
| `end_reason` | string nullable | `range_end`, `risk_pause`, `strategy_stop` |
| `candles_processed` | integer | progress |
| `metrics` | jsonb nullable | summary values, decimal strings |
| `equity_curve` | jsonb nullable | `[{"t":"ISO8601","e":"decimal string"}]`, max 1000 points |
| `error` | text nullable | user-facing failure message |
| `started_at`, `finished_at` | timestamp nullable | |
| timestamps | | |

Indexes: (`user_id`, `created_at`), (`status`).

`backtest_fills`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `backtest_run_id` | FK cascade | |
| `sequence` | integer | deterministic order within the run |
| `side` | string | buy/sell |
| `price`, `quantity`, `fee`, `fee_currency` | numeric / string | |
| `filled_at` | timestamp, UTC | candle close time |
| `grid_level_sequence` | integer nullable | |

Backtest tables are never read by live code.

### 7.3 Configuration snapshot

At creation the run stores, and never updates:

```json
{
  "bot": {
    "id": 42, "name": "BTC grid", "exchange": "binance",
    "symbol": "BTC/USDT", "strategy_key": "simple-grid",
    "fee_rate": "0.001", "capital_allocation": "500",
    "tick_interval_seconds": 60
  },
  "strategy": {
    "key": "simple-grid", "state_version": 1,
    "configuration": { "center_price": "100000", "levels_per_side": 5, "...": "..." }
  },
  "risk": {
    "max_position_notional": "1000",
    "max_open_orders": 20,
    "daily_loss_limit": "100"
  },
  "period": { "timeframe": "15m", "start_at": "...", "end_at": "..." }
}
```

Creation fails with a field error if the bot has no strategy configuration, an
unknown strategy key, or an incomplete configuration for its strategy.

### 7.4 Historical data

- The create form offers `1m`, `5m`, `15m`, `1h`, `4h`, `1d`. Default: `15m`,
  last 30 days.
- Range must be in the past, `start < end`, and yield at most **200,000 candles**
  (validated before queuing).
- `CcxtCandleProvider` pages `fetch_ohlcv` in chunks of up to 1,000, stopping at
  `end_at`, honoring `trading.exchange.rate_limit_ms`, and retrying transient
  failures with the same backoff policy used by `CcxtExchangeClient`.
- Fetched candles are upserted into `market_candles`. A run first checks whether
  the full range is already cached; if so it does no network work. Partial
  coverage fetches only the missing head/tail spans.
- No credentials are needed and none are passed.
- Zero candles after fetch is a failed run with "No historical data available".

### 7.5 Simulation loop

For each candle, in order (mirroring `BotTickPipeline` semantics):

1. Append `close` to `recentPrices`, keeping the last 30.
2. Evaluate the daily-loss check against the close. On breach: cancel all open
   orders, record `risk_pause`, end the run.
3. Build `MarketSnapshot` (`price = close`, no bid/ask) and `BotSnapshot` from the
   in-memory portfolio and open orders.
4. On the first candle, call `initialize()`; otherwise `onTick()`.
5. Sync the in-memory grid to `decision.levels`. If the grid identity changed,
   cancel all open orders and re-evaluate `onTick()` once, matching the pipeline's
   grid-recreation behavior.
6. Apply `decision.cancelClientOrderIds`; emit `CancelEvent` for cancellations,
   including levels retired by a grid replacement.
7. Place `decision.orders` through `BacktestRiskGuard`. Rejections are counted and
   recorded but do not end the run.
8. Evaluate fills for orders that were open at the end of step 7 and are crossed by
   the candle close. Sort by distance from close, then price. Fill the full
   remaining quantity at the limit price, apply the fee, mark the level filled,
   update the portfolio, and call `onFill()` for each fill.
9. Apply the `onFill` decisions: persist state in memory, sync levels, cancel, and
   place orders. Orders created by `onFill` are **not** eligible to fill on the
   same candle; they compete from the next candle.
10. If `shouldStop()` is true, cancel all orders and end the run with
    `strategy_stop`.
11. Append an equity sample at the close.

`decision.events` are not persisted per event; a small count of notable events may
go into metrics later, but v1 keeps the run record lean.

### 7.6 Fill model

Close-only:

- A buy limit fills if `close <= limit`; a sell limit fills if `close >= limit`.
- Fill price is the limit price, not the close.
- Full remaining quantity, no partial fills, no slippage.
- Fee: `limit_price * quantity * fee_rate`, quote currency. This mirrors
  `PaperExchangeClient::qualifyingFills` and `FeeResolver`'s fallback path.
- If both sides of the grid are crossed in one candle, all qualifying orders fill
  in distance order. This is optimistic; it is acceptable for directional
  comparison, and the limitation is shown on the run page.
- Grid levels are walked exactly as in live: a filled buy arms the next level up,
  a filled sell arms the next level down.

This makes results highly dependent on timeframe. The UI states the fill policy and
recommends comparing runs at the same timeframe.

### 7.7 Portfolio accounting

`InMemoryPortfolio` reimplements only the `InventoryLedger::apply` math:

- Buy: add received base (quantity minus base-denominated fee), recompute weighted
  average entry, subtract quote cost and quote fee, subtract quote-valued fee from
  realized P&L.
- Sell: require enough base inventory, subtract base (plus base-denominated fee),
  add quote proceeds minus quote fee, add
  `(price - average_entry) * quantity` to realized P&L, subtract quote-valued fee.
- `fees_paid` accumulates the quote value of every fee.
- `unrealized = (close - average_entry) * base`.
- `equity = quote + base * close`.

A negative-inventory attempt ends the run with `failed` and a clear message; it
indicates a fill-model bug, not a market event.

### 7.8 Starting state

Each run starts **flat**, as a fresh deployment would:

- `quote_inventory = capital_allocation`
- `base_inventory = 0`, `average_entry_price = 0`
- `realized_pnl = 0`, `fees_paid = 0`
- no open orders, new strategy state

A bot's current inventory and realized P&L are **not** carried into the run. The
run page shows this starting assumption.

### 7.9 Risk behavior

The effective limits at creation time are snapshotted: bot-scope limit if present,
otherwise the user-wide limit, using the same resolution as `RiskGuard::limits`.

- Max position notional and max open orders are checked per order, with the same
  formulas as `RiskGuard`.
- Daily loss uses a UTC calendar-day baseline: equity at the first candle of the
  day; a loss beyond the limit pauses the run.
- A risk breach cancels open orders and **ends the run** with `risk_pause`, because
  live bots never auto-resume. The user can start a new run with different params.
- Sell orders require uncommitted base inventory; a short sell is a rejection, not
  a breach, matching `RiskGuard`.

### 7.10 Metrics

Stored as decimal strings:

- `final_price`, `final_base`, `final_quote`, `final_equity`
- `net_pnl`, `return_pct`
- `realized_pnl`, `unrealized_pnl`
- `fees_paid`
- `fill_count`, `buy_fill_count`, `sell_fill_count`
- `max_drawdown`, `max_drawdown_pct` (peak-to-trough on equity)
- `candles_processed`, `end_reason`, `duration_ms`

`return_pct` and `max_drawdown_pct` divide by capital allocation; capital must be
positive (already required for bots).

### 7.11 Equity sampling

Sample equity every candle. Downsample to at most 1,000 points by bucket, keeping
the last sample in each bucket plus the first and last points. Persist as JSON with
ISO timestamps and decimal strings. Comparison uses normalized equity
(`equity / capital_allocation`) so different capital sizes are comparable.

## 8. Dashboard surface

Navigation gains a **Backtests** entry.

| Route | Name | Behavior |
| --- | --- | --- |
| `GET /backtests` | `backtests.index` | User's runs, newest first: name, bot, symbol, timeframe, range, status, net P&L, return, drawdown. Polls every 3s while any run is pending/running. |
| `GET /backtests/create` | `backtests.create` | Bot select (owned bots only), optional name, timeframe, start/end with 7d/30d/90d/1y presets, estimated candle count. |
| `POST /backtests` | `backtests.store` | Validate ownership, range, timeframe, candle cap, strategy completeness; snapshot; queue. Throttled like bot controls. |
| `GET /backtests/{run}` | `backtests.show` | Status, progress, metrics, equity chart, drawdown, fills table, config snapshot, fill-policy note, starting assumption, end reason. Polls every 3s while pending/running. |
| `GET /backtests/compare` | `backtests.compare` | Requires 2–4 selected runs with the same symbol, timeframe, and range. Metrics table plus overlaid normalized equity curves. |
| `DELETE /backtests/{run}` | `backtests.destroy` | Owner only; cascades fills. |

Authorization: a `BacktestRunPolicy` for view/delete; create validates that the bot
belongs to the user. Cross-user access is denied, consistent with bot policies.

Charts: add `chart.js` and `vue-chartjs` as the only new frontend dependency. A
small `EquityChart.vue` wraps a line chart; the comparison page passes multiple
normalized series. If the builder prefers a hand-rolled SVG, the acceptance
criteria remain axes, legend, and an accessible table fallback.

Create defaults: name = `<bot name> · <symbol> · <range>`, timeframe `15m`, range
last 30 days. The form shows the estimated candle count and blocks submission over
200,000.

## 9. Queue and failure handling

- `RunBacktest` implements `ShouldQueue`, `WithoutOverlapping` keyed by run id,
  `tries = 1`, timeout 600 seconds.
- Transitions: `pending -> running -> completed | failed`. Every transition is a
  conditional update so a duplicate job cannot double-run.
- Progress updates `candles_processed` every 500 candles.
- Failures store a concise, non-secret message and mark `failed`; the exception is
  logged with the run id.
- A deleted bot does not affect a run: the snapshot is already persisted.
- A deleted user cascades runs (and fills) via foreign keys.
- Queue worker memory stays bounded: candles are streamed from the provider into
  the engine, and only the downsampled curve and fills are retained.

## 10. Security

- Public market data only; no API keys, no private endpoints, no order submission.
- Run pages and compare are behind `auth` and `verified`, scoped to `user_id`.
- No raw exception traces are rendered; messages are sanitized.
- No new secrets or environment gates.
- Backtest creation is throttled to prevent queue flooding.

## 11. Testing strategy

Unit (no database):

- Candle pagination, range boundaries, retry and empty results with a fake provider.
- Fill rule: buy/sell crossing, fill at limit, full quantity, fees.
- Portfolio math parity against `InventoryLedger` for a fixed set of fills,
  including base-denominated and quote-denominated fees.
- Grid walk: buy fill arms the next level up; sell fill arms the next level down.
- Risk guard: position, open-order, inventory, daily-loss, and pause semantics.
- Metrics and drawdown, including flat and single-point curves.
- Equity downsampling preserves first and last and caps at 1,000.
- End reasons: range end, risk pause, strategy stop.

Parity test:

- Run `PaperExchangeClient::qualifyingFills` and the engine over the same close
  sequence with the same orders; assert identical filled client ids, prices,
  quantities, and fees.

Feature (database, queue fake or sync):

- Create from an owned bot snapshots config; incomplete strategy config is rejected.
- Cross-user bot and cross-user run access are denied.
- Job transitions, progress, completion, and failure paths.
- Compare requires matching symbol/timeframe/range and 2–4 runs.
- Deleting a bot leaves runs readable; deleting a run cascades fills.

Frontend build and PHPStan level 5 must stay clean. Target: domain coverage remains
above the existing 90% gate for new domain classes.

## 12. Performance

- 200,000 candles with risk-capped open orders (default 20) is a few million
  decimal operations; acceptable in a queued job.
- Candle fetch dominates; caching makes repeat runs network-free.
- The engine streams candles, so memory is proportional to the equity sample
  (≤1,000) and fills, not the candle count.
- A run never touches the live tick path or shares a lock with it.

## 13. Builder calls (made here, open to challenge)

1. **Snapshot, no overrides** in v1. Editing a bot never changes an existing run.
2. **Fresh flat start** from `capital_allocation`, not the bot's current inventory.
3. **Risk limits end the run** on breach, mirroring "no auto-resume".
4. **Close-only fills** at the limit price, mirroring paper fills, with the policy
   stated in the UI.
5. **15-minute default timeframe**, 200,000-candle cap.
6. **Chart.js** as the only new dependency.
7. **Comparison requires identical** symbol, timeframe, and range.
8. **No rerun button** in v1; users create a new run. (Cheap to add later.)
9. **UTC everywhere**; the form accepts dates and converts to UTC.
10. **Engine is pure**, so the parity test and future strategy tests need no DB.

## 14. Open questions for the human

None blocking. The calls above are the ones to challenge while reviewing.

## 15. Delivery sketch

1. Candle value object, provider contract, CCXT provider, cache table and store.
2. In-memory portfolio, order book, risk guard, metrics, engine.
3. Parity and unit tests.
4. Backtest run/fill models, migration, job, snapshot builder.
5. Controller, policy, routes, requests, resources.
6. Index, create, detail, compare pages and chart component.
7. Feature tests, PHPStan, build, docs update to `docs/backtesting.md`.
8. OpenSpec change, specs delta, and archive after verification.
