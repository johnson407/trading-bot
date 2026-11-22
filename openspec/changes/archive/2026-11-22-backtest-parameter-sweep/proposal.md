# Proposal

## Why

The backtest engine currently evaluates one fixed configuration at a time. Comparing center price, spacing, and level count means creating runs manually, remembering which parameter changed, and using the generic 2–4 run comparison. That is slow and error-prone, and it hides the best combination across a grid of values. A parameter sweep turns the existing replay engine into a repeatable research workflow.

## What Changes

- Add a backtest **sweep** that runs the cartesian product of center prices, spacing values, and level counts against one bot configuration, timeframe, and date range.
- Store sweep metadata and link each generated `BacktestRun` to its sweep with a label describing the combination.
- Create all runs in one transaction, snapshotting the bot configuration with only the three swept parameters overridden; every other setting remains the bot’s current value.
- Cap a sweep at a configurable number of combinations and reject invalid, duplicate, or oversized value sets before creating any run.
- Add sweep pages: create, list, and detail. The detail page shows each combination’s status, return, drawdown, and end reason, polls while runs are active, and lets the user send 2–4 completed runs to the existing comparison page.
- Add sweep coverage for grid strategies. Non-grid strategies, multiple bots, and arbitrary strategy fields are out of scope.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `backtesting`: adds parameter sweep creation, combination validation, snapshot overrides, run grouping, and sweep results.
- `trading-dashboard`: adds the sweep create, list, and detail pages and their navigation from the backtests area.

## Impact

Affected code: `BacktestController`, a new sweep controller and form requests, `BacktestSnapshotBuilder`, `BacktestRun`/new `BacktestSweep` model, `RunBacktest` dispatch, `routes/web.php`, `Backtests/Index.vue`, `Backtests/Create.vue`, new sweep pages, `config/backtest.php`, and backtest/dashboard tests. Existing runs remain valid and ungrouped. No exchange, trading, risk, or alert behavior changes.
