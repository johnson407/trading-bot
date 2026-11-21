# Tasks

## 1. Historical data foundation

- [x] 1.1 Add the `Candle` value object and the `CandleProvider` contract, and verify a fake provider round-trips candles in ascending open time
- [x] 1.2 Implement `CcxtCandleProvider` with chunked `fetch_ohlcv` pagination, rate-limit spacing, retry/backoff, and normalized errors; verify unit tests cover single-chunk, multi-chunk, empty, and failed responses
- [x] 1.3 Add the `market_candles` migration, model, and `CandleStore` with contiguous-coverage checks, closed-candle trimming, interior-gap fetching, and idempotent upserts; verify tests prove a cached contiguous range skips the provider, a partial or gapped range fetches only missing spans, a forming candle is excluded, and a remaining gap fails the run
- [x] 1.4 Document historical data sources, timeframes, caching, contiguous closed-candle rules, gap failure, and the 200,000-candle cap in `docs/backtesting.md`; verify the documented limits match the implemented validation

## 2. Pure replay engine

- [x] 2.1 Add `BacktestConfiguration`, `BacktestResult`, `BacktestMetrics`, `EquityPoint`, and simulated fill/order value objects; verify they serialize to and from the snapshot and result shapes without floats
- [x] 2.2 Implement `InMemoryPortfolio` with buy/sell average-cost accounting, quote and base fee handling, realized/unrealized P&L, and fee totals; verify parity tests against `InventoryLedger` for fixed fill sequences
- [x] 2.3 Implement `SimulatedOrderBook` and the close-only fill rule, including distance ordering and next-candle eligibility for every order placed during the current candle; verify tests cover crossed, uncrossed, both-side, same-candle exclusion, and fee cases
- [x] 2.4 Implement `BacktestRiskGuard` with quote-capital affordability, position notional, open-order count, uncommitted inventory, and UTC daily-loss checks; verify tests cover unaffordable-buy rejection, order rejection, inventory rejection, breach cancellation, and risk-pause end
- [x] 2.5 Implement the `BacktestEngine` loop with first-candle initialize-then-tick, close-time snapshots, same-candle fill exclusion, sequential per-fill processing, cancel/stop ordering, and grid-replacement re-evaluation; verify tests assert lifecycle order, replay timestamps, and every end reason (`range_end`, `risk_pause`, `strategy_stop`)
- [x] 2.6 Implement `MetricsCalculator` and equity sampling/downsampling to at most 1,000 points; verify tests cover drawdown, flat curves, single-point curves, and preservation of first and last samples
- [x] 2.7 Add the parity test comparing engine fills with `PaperExchangeClient::qualifyingFills` over an identical close sequence; verify ids, prices, quantities, and fees match

## 3. Runs, snapshots, and queued execution

- [x] 3.1 Add `backtest_runs` and `backtest_fills` migrations, models, casts, relationships, and cascade/`nullOnDelete` behavior; verify feature tests cover bot deletion leaving a run readable and run deletion cascading fills
- [x] 3.2 Implement the snapshot builder from an owned bot, its strategy configuration, effective risk limits, and period; verify tests reject foreign bots, missing strategy configs, and unknown strategies, and prove later bot edits do not change the snapshot
- [x] 3.3 Implement `RunBacktest` with conditional transitions, `WithoutOverlapping`, progress updates, timeout, and failure capture; verify feature tests cover pending→running→completed, duplicate-job no-op, and failed runs
- [x] 3.4 Document run statuses, progress, failure messages, and snapshot immutability in `docs/backtesting.md`; verify each documented state matches a test

## 4. HTTP surface and authorization

- [x] 4.1 Add `BacktestRunPolicy`, form requests, and routes for index/create/store/show/compare/destroy with validation for ownership, past range, `start < end`, timeframe, candle cap, and comparison compatibility; verify request tests cover each rejection
- [x] 4.2 Implement `BacktestController` actions and response resources for list, create, store, show, and destroy; verify feature tests cover owner access and cross-user denial
- [x] 4.3 Implement the compare action returning 2–4 compatible runs with metrics and normalized equity series; verify tests accept matching runs and reject symbol, timeframe, or range mismatches
- [x] 4.4 Apply creation throttling and verify repeated submissions are limited without affecting read pages

## 5. Dashboard UI

- [x] 5.1 Add `chart.js` and `vue-chartjs`, implement `EquityChart.vue` with axes, legend, and an accessible table fallback; verify `npm run build` succeeds
- [x] 5.2 Build `Backtests/Index.vue` with run list, status, metrics, empty state, and 3-second polling while active; verify the page renders from the controller props in a feature test and the build passes
- [x] 5.3 Build `Backtests/Create.vue` with owned-bot select, timeframe, range presets, estimated candle count, and field errors; verify a feature test asserts the props and validation feedback
- [x] 5.4 Build `Backtests/Show.vue` with status/progress, metric cards, equity curve, drawdown, fills table, config snapshot, start/fill assumptions, end reason, and polling; verify the page renders completed and failed runs
- [x] 5.5 Build `Backtests/Compare.vue` with the metrics table and overlaid normalized curves; verify the page renders a valid comparison and shows the mismatch error path
- [x] 5.6 Add the Backtests link to desktop and responsive navigation; verify an existing navigation feature test or a new assertion covers both menus

## 6. Integration verification and documentation

- [x] 6.1 Complete `docs/backtesting.md` with the fill model, same-candle eligibility, flat-start and capital-bound assumptions, risk behavior, precision/minimum omission, comparison rules, and directional-not-predictive limitation; verify every statement matches the specs and UI copy
- [x] 6.2 Link the new documentation from `README.md` and verify the link resolves
- [x] 6.3 Run the full test suite, PHPStan level 5, frontend build, and domain coverage gate; verify all pass with no new warnings

## Workflow follow-up

- Validate the change with `openspec validate backtest-engine --strict`.
- Archive the change after implementation, review, and verification are complete.
