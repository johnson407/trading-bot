## 1. Nearest-to-market order priority

- [x] 1.1 Add a strategy intent sorter (distance from market, lower price on ties) and unit tests.
- [x] 1.2 Apply it in runtime paper/live placement and backtest placement, with focused tests proving near intents win under a short limit.

## 2. Gap-aware paper and backtest fills

- [x] 2.1 Fill Go backtest orders at the better of limit and candle close, and Go paper fills at the better of limit and market, with tests for buy and sell gaps.
- [x] 2.2 Apply the same rule in `PaperExchangeClient::qualifyingFills` and add a PHP test.
- [x] 2.3 Review and update backtest golden results affected by gap fills.

## 3. Exchange-rule rounding at plan time

- [x] 3.1 Truncate level prices and quantities to market precision in Go plan evaluation, reject zero and sub-minimum results, and add table tests for both failures.
- [x] 3.2 Add PHP/feature coverage that the plan rejection maps to a field and no bot is saved.

## 4. Dynamic initial spacing

- [x] 4.1 Clamp `initial_spacing` in the fewer-than-two-prices branch and add a strategy test for clamping and fallback.
- [x] 4.2 Wire `initial_spacing` through the dynamic create/edit form, preview payload, Store/Update validation, and backtest configuration; add request tests for valid, omitted, and invalid values.

## 5. Stale disarmed levels

- [x] 5.1 Add a shared prune helper for disarmed entries whose pair is no longer in the ladder.
- [x] 5.2 Apply it to trailing recenter and dynamic recalculated ladders, with strategy tests for stale and kept entries.

## 6. Documentation and specs

- [x] 6.1 Document nearest-first priority, gap fills, rounding, initial spacing, orphaned inventory, and the volatility window in `docs/strategies.md`, `docs/backtesting.md`, and `docs/bot-parameters.md`.
- [x] 6.2 Keep the spec deltas and main specs consistent; run `openspec validate finish-strategy-review-42 --strict`.

## 7. Verification

- [x] 7.1 Run `gofmt` and the full Go suite.
- [x] 7.2 Run the focused PHP tests and the full PHP suite.
- [x] 7.3 Run PHPStan and Pint on changed PHP files.
- [x] 7.4 Run the frontend build and JS tests.
- [x] 7.5 Review `git diff --check` and report the final result.
