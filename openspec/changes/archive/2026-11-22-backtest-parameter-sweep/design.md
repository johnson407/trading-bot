# Design: Backtest Parameter Sweep

## Context

A backtest run snapshots one bot configuration and replays one period. Comparing center prices, spacing values, and level counts currently means creating runs one at a time and using the 2–4 run comparison page. The replay engine, immutable snapshots, queued `RunBacktest` job, and comparison page already exist; this change adds a grouping layer that fans one configuration out into a bounded set of runs.

The proposal explains the motivation. This design covers the data model, creation path, validation, and pages.

## Goals / Non-Goals

### Goals

- Create a sweep from one grid bot, one period, and lists of center prices, spacing values, and level counts.
- Persist each combination as an ordinary `BacktestRun` with an immutable snapshot that differs only in the three swept fields.
- Run the combinations through the existing queue and engine without a new replay path.
- List and inspect sweeps, poll active runs, and hand 2–4 completed runs to the existing comparison page.
- Enforce ownership, a configurable combination cap, and grid-only validation.

### Non-Goals

- Arbitrary strategy parameters or a generic optimization framework.
- Sweeping dynamic-grid `min_spacing` / `max_spacing` or risk/exit rules.
- Multi-bot or multi-period sweeps.
- Automatic “best” selection, scoring, scheduling, or recurring sweeps.
- Canceling an in-flight sweep or deleting sweeps.
- Changing the replay engine, fill model, or comparison page.

## Decisions

### Store sweeps and link runs, rather than encode combinations in run names

Add `backtest_sweeps`:

- `id`, `user_id` (cascade), `bot_id` (nullable, null on delete)
- `name`, `symbol`, `timeframe`, `start_at`, `end_at`
- `parameters` JSON with `center_prices`, `spacing_values`, `level_counts`
- `total_runs` unsigned integer
- timestamps

Add nullable `backtest_runs.sweep_id` with an index and null-on-delete. Runs remain valid standalone backtests if a sweep is removed.

Sweep status is derived from its runs, not stored. `total_runs` records the intended batch size; actual completed and failed counts come from `withCount` and status queries. This avoids a second source of truth that could drift when a job fails or a run is deleted.

### Generate the cartesian product in one action

`CreateBacktestSweep` receives the validated request and the bot, builds the combinations, and inside one database transaction creates the sweep and all runs. Each run copies the base snapshot and overrides only:

- `center_price`
- `spacing_value`
- `level_count` in the configuration, plus the strategy’s `levels_per_side` mapping

The existing `BacktestSnapshotBuilder::build()` gains an optional `array $strategyOverrides = []` argument. The builder applies the overrides after loading the strategy configuration and before encoding the snapshot. Passwords, risk limits, exit rules, fees, and capital remain the bot’s effective values.

Jobs dispatch after the transaction commits. A dispatch failure is logged; `backtests:fail-stale` already fails runs that never start, so one failed dispatch does not leave the sweep permanently pending.

### Validate the set before writing anything

`StoreBacktestSweepRequest` validates one bot, timeframe, date range, and three non-empty arrays. Center prices and spacing values are positive decimals; level counts are integers from 1 to 50; duplicates are rejected. The request also rejects non-grid strategies.

The cartesian count must be at least 2 and at most `config('backtest.sweep_max_runs', 24)`. The cap is checked after deduplication, so a user cannot bypass it with repeated values. Period and candle-count validation matches the single-run flow.

### Reuse the run pages and comparison route

Routes live under the existing authenticated, verified group:

- `GET /backtests/sweeps` (`backtest-sweeps.index`)
- `GET /backtests/sweeps/create` (`backtest-sweeps.create`)
- `POST /backtests/sweeps` (`backtest-sweeps.store`)
- `GET /backtests/sweeps/{sweep}` (`backtest-sweeps.show`)

`BacktestSweepController` owner-scopes every lookup. The index lists the latest sweeps with derived counts. The create page loads the owner’s bots that have grid configurations and shows the live combination count and cap. The detail page lists each run’s swept values, status, period return, drawdown, and end reason; it polls while any run is active and builds a `backtests.compare` URL from 2–4 completed runs.

The existing `Backtests/Index.vue` gains a link to the sweep index. No change is needed to `Backtests/Compare.vue`.

### Keep the fan-out bounded

The default cap of 24 keeps a sweep within the existing queue and comparison workflow: enough to explore a small grid, small enough that a single request cannot create an unbounded job batch. The cap is configuration, not a database constraint, so deployments can raise it deliberately.

## Risks / Trade-offs

- **Queue burst.** A 24-run sweep dispatches 24 jobs at once. Horizon workers absorb this, but a busy queue delays completion. The cap and existing retry/stale handling bound the damage.
- **Partial failure.** Some combinations can fail while others complete. The sweep remains inspectable and reports completed/failed counts; it does not retry the whole batch.
- **Snapshot duplication.** Each run stores a full snapshot. At the cap this is small relative to candle data and preserves standalone run behavior.
- **Derived status queries.** The index needs aggregate counts. Eager `withCount` keeps this to one query per page.
- **Sweep-only fields.** Only the three supported fields are overridden. Other strategy fields come from the bot, so a sweep cannot accidentally change capital, fees, risk, or exits.

## Open Questions

None.
