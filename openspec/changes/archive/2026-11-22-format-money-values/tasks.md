# Tasks

## 1. Shared money formatter

- [x] 1.1 Create `resources/js/Support/money.js` with `formatMoney(value)` and `formatSignedMoney(value)` per design.md: decimal-string half-up rounding to two places, no grouping, no scientific notation, and `-0.00` normalized to `0.00`. Verify by importing both functions in a Node test and checking `500.00000000` → `500.00`.
- [x] 1.2 Add `resources/js/Support/money.test.js` using `node:test` and cover `500.00000000` → `500.00`, `500.126` → `500.13`, `-25.5` → `-25.50`, `0` → `0.00`, `-0.001` → `0.00`, `1.005` → `1.01`, blank → `0.00`, and signed positive output. Add a `test:js` script to package.json and verify `npm run test:js` passes.

## 2. Read-only monetary surfaces

- [x] 2.1 Update `resources/js/Pages/Dashboard.vue` to import the shared formatter and use it for realized P&L, unrealized P&L, peak equity, and equity chart values; remove the local `money`/`signedMoney` definitions. Verify `npm run build` succeeds and a stored `1000.0000000000000000` renders as `1000.00`.
- [x] 2.2 Update `resources/js/Pages/Bots/Index.vue` and `resources/js/Pages/Bots/Show.vue` to use the shared formatter for realized/unrealized P&L, grid and level prices, reference price, quote inventory, average entry price, order and fill prices, fill fees, fees paid, and risk-limit money values. Leave base inventory and order/fill quantities as quantities. Verify `npm run build` succeeds and no raw monetary binding such as `{{ bot.realized_pnl }}` remains.
- [x] 2.3 Update `resources/js/Pages/Backtests/Show.vue` and `resources/js/Pages/Backtests/Compare.vue` to use the shared formatter for capital, final quote inventory, final equity, net/realized/unrealized P&L, fees, fill prices, and fill fees. Keep fill quantity, final base inventory, fee rate, percentages, and `config_snapshot` unchanged. Verify `npm run build` succeeds and fees show two decimals while quantities keep their precision.
- [x] 2.4 Update `resources/js/Pages/Backtests/Sweeps/Show.vue`, `resources/js/Pages/Backtests/Sweeps/Create.vue`, and `resources/js/Pages/Backtests/Create.vue` to format center prices, arithmetic spacing values, swept pills, and the selected bot's capital in helper text. Keep fee rates as rates and geometric spacing at its stored precision. Verify `npm run build` succeeds and `500.00000000` appears as `500.00` in the swept values and run table.
- [x] 2.5 Add a formatting mode to `resources/js/Components/EquityChart.vue` so monetary charts use two-decimal formatting and the normalized comparison chart keeps four decimals in the tooltip and data table. Verify `npm run build` succeeds, the dashboard and backtest equity tooltips show two decimals, and the compare chart still distinguishes normalized values.

## 3. Editable monetary forms

- [x] 3.1 Update `resources/js/Pages/Bots/Edit.vue` to seed `capital_allocation` with `formatMoney` while retaining the raw prop, and submit the raw value when the field is unchanged. Verify with a bot stored at `1000.0000000000000000`: the field shows `1000.00`, and saving without editing leaves the stored value unchanged.
- [x] 3.2 Update `resources/js/Pages/Bots/Show.vue` to seed protective-exit `range_low` and `range_high` with `formatMoney`, retain their raw values, and submit the raw value when unchanged. Leave `stop_loss_percent` and `take_profit_percent` untouched. Verify an 8-decimal range bound displays as two decimals and survives an unchanged save.
- [x] 3.3 Update `resources/js/Pages/RiskLimits/Edit.vue` to seed `max_position_notional` and `daily_loss_limit` with `formatMoney` while retaining the raw values, and submit the raw value when unchanged. Verify a limit stored at `2500.0000000000000000` shows `2500.00` and survives an unchanged save.

## 4. Integration verification

- [x] 4.1 Run `npm run build` and confirm the Vite production build completes without errors or Vue compile warnings.
- [x] 4.2 Run `npm run test:js` and confirm all money formatter tests pass.
- [x] 4.3 Run the PHP test suite with `docker compose exec app ./vendor/bin/pest` (or the project's configured equivalent) and confirm it passes; if the container is unavailable, record that it could not be run.
- [x] 4.4 Grep the Vue pages for remaining monetary bindings and confirm the identified leaks are gone: capital allocation, P&L, fees, prices, inventory quote and average entry, equity, and risk limits. Confirm quantities, fee rates, percentages, timestamps, and the snapshot JSON were not reformatted.
- [x] 4.5 Spot-check the dashboard, bot list, bot detail, bot edit, risk limits, backtest detail, backtest compare, and sweep detail and confirm each monetary value shows two decimals while non-monetary values keep their existing precision.

## 5. Review fixes

- [x] 5.1 Pass `spacing_type` through the sweep page payload and keep geometric spacing at its stored precision instead of formatting it as money.
- [x] 5.2 Re-seed the protective-exit and risk-limit forms after a successful save so display precision and raw values stay in sync.
- [x] 5.3 Extract the unchanged-vs-edited decision into `submittedMoney` and cover it, plus additional formatter edge cases, with Node tests.
- [x] 5.4 Re-run the frontend build, JS tests, and PHP suite after the review fixes.

## Workflow follow-up

- Archive the change after the project's review requirements are satisfied.
- Verify the archived result and the updated main spec.
