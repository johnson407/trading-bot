# Design

## Context

See `proposal.md` for motivation and `specs/` for the behavior contract.

Current state that shapes the approach:

- Strategies are pure. `App\Domain\Strategy\Contracts\StrategyInterface` takes
  immutable `StrategyInput` and returns `StrategyDecision`.
- `BotTickPipeline` owns live orchestration but is coupled to Eloquent, the queue,
  `ExchangeClientFactory`, activity logging, and risk-event recording. Reusing it
  would require a sandbox database and would write live-shaped rows per tick.
- `ExchangeClient` is order-oriented and has no candle method. Historical data
  needs a separate provider at the public market-data boundary.
- Money and quantities are exact decimal strings (`App\Domain\Support\Decimal`);
  no floats in domain or persistence.
- `FeeResolver` and `InventoryLedger` define fee and average-cost semantics. The
  backtest must match them and prove it with tests.
- Stack: PHP 8.4, Laravel 12, PostgreSQL, Redis/Horizon, Inertia + Vue 3.

## Goals / Non-Goals

**Goals:**

- Directionally trustworthy replay of a bot configuration against historical data.
- Saved runs with metrics, fills, and an equity curve suitable for comparison.
- A side-effect-free engine that is unit-testable without a database.
- Exact fee and P&L arithmetic parity with the existing live/paper code.
- Bounded runtime and memory for ranges up to 200,000 candles.
- No change to live or paper trading behavior.

**Non-Goals:**

- Tick-level, order-book, slippage, or partial-fill simulation.
- Parameter optimization, walk-forward analysis, or config overrides.
- Standalone backtest configuration, CSV import, or multi-symbol runs.
- Any change to `BotTickPipeline`, live execution, or the existing bot tables.
- A public API or automated deployment of results.

## Decisions

### D1. Dedicated in-memory engine (chosen over pipeline replay and shared kernel)

A `BacktestEngine` walks candles, builds `StrategyInput` from in-memory state, and
calls the registered strategies. It returns a `BacktestResult` value object.

- **Alternative A — replay through `BotTickPipeline`:** parity by construction,
  but Eloquent per tick, sandbox isolation, activity/risk writes, and hundreds of
  thousands of ORM calls. Rejected.
- **Alternative C — extract a shared simulation kernel:** best long-term parity,
  but refactors the production tick path before a baseline exists. Rejected for
  v1.
- **Mitigation for the chosen approach:** a parity test drives both
  `PaperExchangeClient::qualifyingFills` and the engine over the same closes and
  asserts identical fill ids, prices, quantities, and fees; portfolio math is
  tested against `InventoryLedger` on fixed fills.

The engine receives a `BacktestConfiguration` and an iterable of `Candle` value
objects. It does not read Eloquent or call an exchange.

### D2. Historical data through a public provider with a database cache

- New `CandleProvider` contract; `CcxtCandleProvider` pages `fetch_ohlcv` in
  chunks of up to 1,000, stops at `end_at`, honors
  `trading.exchange.rate_limit_ms`, and retries transient failures with the same
  backoff policy as `CcxtExchangeClient`.
- Public endpoints only; no credentials are read or passed.
- `market_candles` caches by unique (`exchange`, `symbol`, `timeframe`,
  `open_time`). Coverage means one closed candle for every interval in the range,
  with no interior gaps. A run checks contiguity first, fetches missing spans
  (including interior gaps), and upserts. If gaps remain after fetching, the run
  fails rather than silently simulating across them.
- Only closed candles are fetched and simulated. A candle is included only when
  its close time is at or before the run's start instant; the still-forming
  candle at the requested end is trimmed. Time-based strategies, daily-loss
  boundaries, and metrics therefore see only completed intervals.
- Timeframes: `1m`, `5m`, `15m`, `1h`, `4h`, `1d`. Default `15m`; default range
  last 30 days; hard cap 200,000 candles validated before queuing.
- Zero candles is a failed run with "No historical data available".

### D3. Close-only fill model at the limit price

- Buy fills when `close <= limit`; sell fills when `close >= limit`.
- Fill price is the limit, not the close. Full remaining quantity.
- Fee = `limit * quantity * bot.fee_rate` in quote currency, matching
  `PaperExchangeClient::qualifyingFills` and `FeeResolver`'s fallback.
- All crossed orders fill in distance-from-close order, then price order.
- Orders placed during the current candle — whether by `onTick` or by `onFill` —
  are not eligible to fill until the next candle. Fills are evaluated only for
  orders that were open before this candle's placements, matching the live paper
  path's exclusion of orders placed in the current tick.
- Results depend on timeframe; the UI discloses this and recommends same-timeframe
  comparisons.

### D4. Flat starting state

Every run starts with `quote = capital_allocation`, `base = 0`,
`average_entry = 0`, `realized = 0`, `fees = 0`, no open orders, and new strategy
state. Current bot inventory is not carried in, so the run represents a fresh
deployment. The run page states this.

Starting capital also bounds buys: a buy is rejected when its notional plus fee
exceeds free quote inventory. This is a deliberate backtest guard that live paper
mode does not enforce, so a fresh deployment cannot spend more than its
allocation and equity cannot be inflated by unbacked buying.

### D5. Risk limits are snapshotted and enforced

The effective limits at creation (bot scope if present, otherwise user scope, using
`RiskGuard`'s precedence) are stored in the snapshot. The engine enforces maximum
position notional, maximum open orders, uncommitted inventory on sells, and the
UTC daily loss limit.

A position/open-order/inventory rejection skips that order and continues. A daily
loss breach cancels open orders and ends the run with `risk_pause`, because live
bots never auto-resume.

### D6. Simulation order mirrors the live tick

Per candle:

1. Append the close to the 30-price window and check the daily-loss baseline.
2. Build snapshots with the candle's **close time** as `MarketSnapshot.timestamp`,
   not wall-clock `now`. Time-based strategies and day boundaries must advance
   with the replay, and the close is the observed price for the candle.
3. On the first candle, call `initialize()` then `onTick()` with the initialized
   state. Initial grid creation is not a replacement, so it does not trigger an
   extra re-evaluation. On later candles, call `onTick()`.
4. Sync levels and re-evaluate once only when an existing grid is replaced.
5. Apply cancels and `onCancel`.
6. Place intents through the risk guard.
7. Fill only orders that were open **before this candle's placements**. Process
   each fill sequentially through portfolio update, `onFill`, grid sync, and
   order placement before moving to the next fill. Built-in strategies mutate
   fill counts, martingale size, disarmed levels, and trailing/dynamic state, so
   batching fills would pass stale state and change later orders.
8. Check `shouldStop()`; sample equity at the close.

This preserves the live tick's observable ordering while keeping persistence out
of the loop. Exchange precision rounding and minimum amount/notional checks are
not re-applied in v1: orders use the values the strategy produced. That
simplification is disclosed with the other assumptions.

### D7. Persistence is additive and separated from live tables

- `market_candles`: exchange, symbol, timeframe, open time, OHLCV, unique key.
- `backtest_runs`: owner, nullable bot id (`nullOnDelete`), denormalized
  exchange/symbol/strategy/timeframe, range, capital, fee rate, immutable
  `config_snapshot` JSON, status, end reason, progress, `metrics` JSON,
  `equity_curve` JSON (≤1,000 points), error, timestamps.
- `backtest_fills`: run id (cascade), sequence, side, price, quantity, fee, fee
  currency, filled at, grid level sequence.

Backtest tables are never read by live code. A deleted bot leaves its runs readable;
a deleted user cascades runs and fills.

### D8. Queued execution with conditional transitions

`RunBacktest` implements `ShouldQueue`, uses `WithoutOverlapping` keyed by run id,
`tries = 1`, timeout 600 seconds, and updates progress every 500 candles.
Transitions are `pending -> running -> completed | failed` via conditional updates,
so a duplicate job cannot double-run. Failures store a concise non-secret message
and log the exception with the run id.

### D9. Owner-scoped dashboard with compatible comparison

Routes:

- `GET /backtests` — list, newest first, poll while active.
- `GET /backtests/create` and `POST /backtests` — choose an owned bot, timeframe,
  and past range with presets; show estimated candle count.
- `GET /backtests/{run}` — status, progress, metrics, equity chart, fills,
  snapshot, assumptions, end reason; poll while active.
- `GET /backtests/compare` — 2–4 runs with identical symbol, timeframe, and range;
  metrics table plus overlaid normalized equity (`equity / capital`).
- `DELETE /backtests/{run}` — owner only.

A `BacktestRunPolicy` handles view/delete; creation validates bot ownership.
Cross-user access is denied. Creation is throttled like bot controls.

### D10. Chart.js is the only new frontend dependency

`chart.js` + `vue-chartjs` behind an `EquityChart.vue` wrapper. Comparison passes
multiple normalized series. A table fallback remains accessible. A hand-rolled SVG
would work but costs more for axes, tooltips, and overlays.

### D11. Testing targets the arithmetic and the seams

- Unit: provider pagination, contiguity, closed-candle trimming, and failures;
  fill rule; same-candle eligibility; first-candle `initialize` + `onTick`;
  sequential fill processing; fee; portfolio parity with `InventoryLedger`; grid
  walk; capital affordability and risk rejections; daily-loss pause; metrics and
  drawdown; equity downsampling; end reasons; replay timestamps.
- Parity: engine vs `PaperExchangeClient::qualifyingFills` on a fixed sequence.
- Feature: snapshot creation, ownership denial, job transitions, failure paths,
  comparison validation, bot deletion behavior.
- Gates: PHPStan level 5, frontend build, existing coverage floor for new domain
  classes.

### D12. Security boundary is unchanged

Public data only, no API keys, no order submission. All pages behind `auth` and
`verified`, scoped by `user_id`. No secrets, no new environment gates, no raw
traces rendered.

## Risks / Trade-offs

- **Optimistic fills** → disclose the policy on every run; require matching
  timeframe for comparison; keep the model easy to replace later.
- **Divergence from live accounting** → shared test vectors against
  `InventoryLedger` and `PaperExchangeClient`; any change to fee or P&L rules must
  update both sides.
- **Exchange history limits or gaps** → require contiguous closed candles, cache
  partial spans, and fail with "data gap" rather than simulating across holes; a
  future provider can backfill gaps.
- **Capital guard diverges from live paper mode** → live paper can spend quote it
  does not hold, while a backtest rejects unaffordable buys. This is intentional
  for a fresh deployment and is disclosed; parity tests target fill mechanics,
  not affordability.
- **No exchange precision/minimum enforcement** → results may include orders live
  would round or reject. Disclosed as a v1 simplification; a later version can
  snapshot trading rules at run start.
- **Long runs** → 200,000-candle cap, streaming iteration, progress updates, and a
  600-second job timeout.
- **Cache growth** → unique key and range-first reads; a retention policy can be
  added without schema change.
- **Deleted source bot** → immutable snapshot and `nullOnDelete` keep runs
  readable.
- **Chart dependency** → one small, widely used package; no other UI dependency
  changes.

## Migration Plan

1. Add three additive migrations and models. No existing table or column changes.
2. Add the provider, engine, job, snapshot builder, controller, policy, requests,
   and routes behind authentication.
3. Add the Vue pages, navigation link, and Chart.js dependency; run the frontend
   build.
4. Add unit, parity, and feature tests; run PHPStan and the existing suite.
5. Deploy: run migrations and restart Horizon so the new job class is loaded.
6. Rollback: stop queued runs, roll back the three migrations, and redeploy the
   previous release. No live trading data is affected.

No new environment variables are required.
