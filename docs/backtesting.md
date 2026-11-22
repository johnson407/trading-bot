# Backtesting

Backtesting replays an existing bot's strategy against cached historical candles
and saves the result in the dashboard. It is intended to answer one question
before capital is committed: *would this configuration have made or lost money
over this period?* It is a directional aid, not a prediction.

The implementation lives in four layers:

| Layer | Location |
| --- | --- |
| Historical candles | `App\Services\Backtest\CcxtCandleProvider`, `CandleStore`, `market_candles` |
| Side-effect-free replay | `App\Domain\Backtest` |
| Saved runs and dashboard | `App\Models\BacktestRun`, `RunBacktest`, `BacktestController` |
| Parameter sweeps | `App\Models\BacktestSweep`, `CreateBacktestSweep`, `BacktestSweepController` |

## Creating a run

Open **Backtests → New backtest**, choose one of your bots, a timeframe, and a
past date range. The bot must already have a saved strategy configuration. The
form shows the approximate candle count before submitting.

- Timeframes: `1m`, `5m`, `15m`, `1h`, `4h`, `1d`.
- Defaults: `15m` over the last 30 days.
- Maximum: 200,000 candles. Larger ranges are rejected before a run is created.
- The range must end in the past and end after it starts. The end date is
  interpreted as 00:00 UTC, so it is exclusive; choose the following day to
  include a full final day.
- Cached candles are read lazily and fetched/written in bounded chunks, so a
  long range does not hydrate every candle row at once. The saved equity curve
  is capped at 1,000 points.
- A run is stopped with a clear failure if it produces more than 25,000 fills.
  This protects the worker from pathological fill-heavy configurations; use a
  shorter range or larger timeframe instead.

Creation snapshots the bot's name, exchange, symbol, strategy key, strategy
configuration, capital allocation, fee rate, effective risk limits, protective
exit rules, and period.
The snapshot is stored immutably on the run. Later edits to the bot do not change
an existing run. If the bot is deleted, the run remains readable.

## Historical candles

Candles come from the exchange's **public** OHLCV endpoint. No API key is read or
sent. The provider pages through `fetch_ohlcv` in chunks of up to 1,000, waits
between requests according to `trading.exchange.rate_limit_ms`, and retries only
transient connection and rate-limit failures with the same exponential backoff as
the live CCXT client.

Candles are cached in `market_candles`, keyed by exchange, symbol, timeframe, and
open time. A run:

1. Reads the requested range from the cache.
2. Fetches only missing spans, including interior gaps.
3. Re-reads the merged range and checks that every interval is present.
4. Fails with a data-gap error rather than simulating across a hole.

Only fully closed candles are used. A candle is included only when its close time
is at or before the requested end and at or before the current time, so a candle
still forming at the end of the range is trimmed. If the exchange returns no
candles for a range, the run fails with `No historical data available.`

Cached rows are safe to reuse across bots and runs. There is no retention
policy yet; delete old `market_candles` rows if cache size becomes a concern.

## What the replay does

Each run starts from scratch, not from the bot's current live inventory:

- Quote inventory = the bot's capital allocation.
- Base inventory = 0.
- Average entry price = 0.
- Realized P&L = 0.
- Fees paid = 0.
- No open orders, and a new strategy state.

The replay then processes candles in order. For every candle it appends the close
to the 30-price window, builds `StrategyInput` with the candle's **close time**,
and calls the strategy lifecycle:

- The first candle calls `initialize()` and then `onTick()` with the initialized
  state. Creating the initial grid is not a grid replacement, so it does not
  trigger another tick.
- Later candles call `onTick()`.
- If a grid is replaced, the strategy is re-evaluated once against the new book.
- Cancellations are applied and reported through `onCancel()`.
- Orders are placed through the backtest risk guard.
- Fill processing is sequential: portfolio update → `onFill()` → grid sync →
  new orders → next fill. This preserves martingale sizing, disarmed levels, and
  trailing/dynamic state.

The replay never writes bot, order, fill, risk, activity, or inventory records.
Only the run's own metrics, equity curve, and simulated fills are persisted.

## Fill model

The fill model is deliberately simple and optimistic:

- A buy limit fills when the candle close is at or below the limit.
- A sell limit fills when the candle close is at or above the limit.
- The fill price is the **limit price**, not the close.
- The full remaining quantity fills; there are no partial fills.
- There is no slippage, fee discount, or order-book impact.
- Crossed orders fill closest to the close first, then by price.
- Orders placed during a candle are not eligible until the next candle, matching
  the live paper path's same-tick exclusion.

Each fill pays `limit × quantity × bot fee rate` in the quote currency. Fees are
deducted from quote inventory and included in realized P&L and reported fees.

Exchange precision rounding and minimum amount/notional checks are **not**
re-applied in this version. Orders are simulated with the values the strategy
produced. A live exchange could round or reject some of those orders.

## Capital and risk

Starting capital bounds buying: a buy is rejected when its notional plus fee
exceeds free quote inventory. This guard is intentional for a fresh deployment
and is stricter than live paper mode, which does not enforce quote affordability.
The run continues after a rejected order.

The snapshot includes the effective risk limits and protective exit rules at
creation: the bot-specific limit when one exists, otherwise the user-wide limit.
The replay enforces:

- Maximum position notional.
- Maximum open orders.
- Uncommitted base inventory for sells.
- UTC daily loss limit.
- Stop-loss, take-profit, and range exits.

A position, open-order, or inventory rejection skips that order and continues.
A daily-loss breach cancels open orders and ends the run with `risk_pause`. Runs
never resume automatically. Daily loss resets at the UTC calendar-day boundary
during the replay.

Protective exits are evaluated once per candle, at the close, before the
daily-loss check and before the strategy sees the candle. A stop-loss or
take-profit needs positive base inventory and a positive average entry price; the
range rules work without inventory and may use only one bound. Precedence is
stop-loss, then take-profit, then range, with one trigger per candle. A trigger
cancels simulated open orders and ends the run with `stop_loss`, `take_profit`, or
`range_exit`. It does not sell the remaining base inventory. Because evaluation is
close-only, a candle that trades through a threshold intrabar is detected at its
close, so the recorded exit price can be worse than the configured threshold.

`risk_pause`, `strategy_stop`, `stop_loss`, `take_profit`, and `range_exit` are
terminal outcomes for the saved run, just as a paused live bot does not resume by
itself.

## Outcomes and metrics

A run can end for any of these reasons:

| End reason | Meaning |
| --- | --- |
| `range_end` | The replay consumed the requested range |
| `risk_pause` | The UTC daily loss limit was breached |
| `strategy_stop` | The strategy requested a stop |
| `stop_loss` | The stop-loss threshold was reached or crossed |
| `take_profit` | The take-profit threshold was reached or crossed |
| `range_exit` | The mark price left the configured range |

The completed run stores:

- Final equity, final base and quote inventory, and final price.
- Net P&L, return percent, realized P&L, unrealized P&L, and fees.
- Fill, buy, and sell counts.
- Maximum drawdown in quote currency and as a percentage of the running peak.
- Candles processed and run duration.
- Simulated fills.
- An equity curve sampled at candle closes.

The equity curve is capped at 1,000 points. Longer runs are evenly downsampled
while preserving the first and last samples. The full fill list is retained in
`backtest_fills`; the detail page displays up to the first 500 fills.

## Run lifecycle

Runs move through `pending → running → completed` or `pending → running → failed`.

- `RunBacktest` is queued on the dedicated `backtests` queue with one attempt and
  a 600-second job timeout.
- `WithoutOverlapping` is keyed by run id, and the pending-to-running update is
  conditional, so a duplicate job cannot execute a run twice.
- Progress and candles processed update every 500 candles.
- Redis redelivery is configured above the job timeout, and a scheduled
  `backtests:fail-stale` sweep fails runs whose worker disappeared.
- Failures store a short, non-secret message and log the exception with the run
  id. Raw traces are not shown in the dashboard.

The list and detail pages poll every three seconds only while a run is pending or
running; polling stops when the run finishes. Horizon must be running for queued
backtests to execute, and the `backtests` supervisor uses a larger memory limit
and a longer timeout than bot ticks.

## Comparing runs

The detail page offers comparison when at least one other completed run shares
the same symbol, timeframe, start, and end. The compare page accepts two to four
completed runs and shows:

- A metrics table.
- Overlaid equity curves normalized by starting capital (`equity / capital`).

Runs with a different symbol, timeframe, or range are rejected with an
explanation. Comparing results from different timeframes is misleading because
the fill model observes one close per interval.

## Parameter sweeps

A parameter sweep is one grid bot, one timeframe, and one date range evaluated
across lists of center prices, spacing values, and level counts. Open
**Backtests → Parameter sweeps → New sweep**, select a configured grid bot, and
enter each list as comma-separated values.

The sweep creates the **cartesian product**: every center price is combined with
every spacing value and every level count. For example, two centers, two spacings,
and two level counts produce eight runs. The create page shows the live
combination count before submission.

- A sweep must produce at least 2 and at most `backtest.sweep_max_runs` runs. The
  default cap is 24; deployments can raise it in configuration, but the effective
  minimum stays at 2.
- Center prices and spacing values must be positive decimals. Level counts must be
  whole numbers from 1 to 50.
- Duplicate values are rejected after decimal normalization, so `50000` and
  `50000.0` count as the same center price and cannot bypass the cap.
- Non-grid strategies, another owner's bot, an unconfigured bot, an invalid date
  range, and a range above the candle limit are rejected before any row is
  created. A rejected request creates neither a sweep nor a run.
- Only `center_price`, `spacing_value`, `level_count`, and the strategy's
  `levels_per_side` mapping are overridden in each run's immutable snapshot.
  Capital, fees, risk limits, exits, order quantity, spacing type, and every other
  setting come from the bot.

Creation is atomic: the sweep and all of its runs are inserted in one transaction,
then one `RunBacktest` job is dispatched per run after the commit. If a dispatch
fails, the failure is logged and that run is immediately marked `failed` with
`The backtest job could not be queued.`, so the sweep reaches a terminal state
instead of waiting forever. The scheduled `backtests:fail-stale` command also
fails stale running runs and pending runs that never started.

Each generated run stores `sweep_id` and a name describing its combination, and
behaves like a normal backtest: it has its own snapshot, status, progress, end
reason, metrics, equity curve, and fills. Sweeps do not have a user-facing delete
action. If a sweep row is removed at the database level (or through user
deletion), `sweep_id` is nulled on its runs without deleting them; deleting a bot
also leaves the runs readable.

Sweep status is derived from its runs rather than stored:

- `incomplete` when the sweep no longer has any runs.
- `pending` while runs are queued and none has started.
- `running` while any run is running.
- `failed` once all runs are finished and at least one failed.
- `completed` when every run completed.

The list and detail pages show completed, failed, and active counts plus the
percentage of runs that have finished. They poll every three seconds only while a
sweep has pending or running runs.

The detail page lists each combination's swept values, status, return, maximum
drawdown, and end reason, and links to each run. When two to four runs have
completed, it offers **Compare completed**, which hands those runs to the existing
comparison page. A partially failed sweep stays inspectable; it does not retry the
whole batch.

## Stated assumptions

Every run detail page repeats these limitations:

1. Fills are close-only, at the limit, in full, with no slippage.
2. The run starts flat with the bot's capital allocation, not its live inventory.
3. Buying is bounded by available quote inventory; same-candle orders wait.
4. Exchange precision and minimum rules are omitted.
5. Protective exits are evaluated at candle close, not intrabar.
6. Results are directional, not predictive.

## Operational notes

- Candle fetching is public and does not require an exchange credential.
- Backtest tables are additive and never read by live trading code.
- Deleting a user removes their runs and fills. Deleting a bot sets the run's
  `bot_id` to null without deleting the run.
- The source bot, strategy configuration, and risk limit rows are not modified.
- Queue workers and the database must be healthy; a run that cannot fetch data
  fails visibly rather than silently completing with no trades.
- The Redis queue `retry_after` default is 660 seconds so it exceeds the
  backtest job timeout. Deployments with their own queue configuration should
  keep that invariant.

## Verification

Run the focused checks with Docker:

```bash
docker compose exec -T -e APP_ENV=testing -e CACHE_STORE=array app php artisan test \
  tests/Unit/BacktestEngineTest.php \
  tests/Unit/BacktestComponentsTest.php \
  tests/Unit/CcxtCandleProviderTest.php \
  tests/Feature/CandleStoreTest.php \
  tests/Feature/BacktestParityTest.php \
  tests/Feature/BacktestExitGuardTest.php \
  tests/Feature/RunBacktestTest.php \
  tests/Feature/BacktestDashboardTest.php \
  tests/Feature/BacktestSweepTest.php \
  tests/Feature/BacktestSweepDashboardTest.php \
  tests/Feature/StoreBacktestSweepRequestTest.php
```

The parity tests compare portfolio arithmetic with `InventoryLedger` and the fill
rule with `PaperExchangeClient::qualifyingFills`. If either production rule
changes, update both sides and rerun those tests.

## Test map

| Flow | Test |
| --- | --- |
| Sweep schema and run link | `BacktestSweepMigrationTest` |
| Sweep model relations and cascade behavior | `BacktestSweepModelTest` |
| Sweep configuration cap | `BacktestSweepConfigTest` |
| Cartesian creation, overrides, rollback, dispatch failure | `BacktestSweepTest` |
| Snapshot strategy overrides | `BacktestSnapshotBuilderTest` |
| Sweep request validation and ownership | `StoreBacktestSweepRequestTest` |
| Sweep pages, derived counts, compare link, end-to-end | `BacktestSweepDashboardTest` |
