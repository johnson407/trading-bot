# Tasks

## 1. Dynamic grid order replenishment (#38)

- [x] 1.1 Update `engine/internal/strategy/dynamic.go` so the non-due branch retains the current state and levels and emits order intents for every armed level without an open order; verify with a new strategy unit test covering missing orders, existing orders, and disarmed levels.
- [x] 1.2 Update `engine/internal/runtime/decide_internal_test.go::TestDecideInitializedStateIsNotReinitialized` to expect the restored orders while still asserting that the marker and `recalculation_count` are unchanged; verify the focused runtime test passes.
- [x] 1.3 Rename the `on_tick_before_interval_is_unchanged` case in `tests/golden/engine/strategies.json` to `on_tick_before_interval_replenishes_orders`, update its expected orders, and verify `go test ./internal/strategy` passes.
- [x] 1.4 Update `docs/strategies.md` to state that dynamic re-places missing orders between recalculations and verify the described behavior matches the new tests.

## 2. Backtest first candle uses the normal tick path (#37)

- [x] 2.1 Remove the `index == 0` initialize-then-tick special case in `engine/internal/backtest/engine.go` so every candle calls `OnTick`, with new state on the first candle; verify a new backtest test with a dynamic interval longer than one candle places the initial ladder on the first candle.
- [x] 2.2 Add a backtest regression test proving the first dynamic fill can occur from the second candle onward and that the first candle does not skip its ladder; verify the focused backtest test passes.
- [x] 2.3 Recompute and review the `dynamic_grid_recalculates` case in `tests/golden/engine/backtest_replay.json` for the first-candle behavior; verify `go test ./internal/backtest` passes and record why the changed values are correct.
- [x] 2.4 Update `docs/backtesting.md`'s first-candle lifecycle bullets so the first candle calls `onTick()` with a new state; verify the documented behavior matches the new test names.

## 3. Grid replacement preserves shared levels (#39)

- [x] 3.1 Add a runtime decision-boundary regression test where a spacing change replaces the grid and prices shared by the old and new ladders must receive orders; verify the test fails before the dynamic replenishment change and passes after it.
- [x] 3.2 Update the backtest second-pass handling in `engine/internal/backtest/engine.go` so a second evaluation that returns no orders does not discard the first evaluation's orders for that candle. Extract the merge into a helper such as `mergeReplacementOrders(first, second []strategy.OrderIntent)` and add a focused unit test for the empty-second-pass fallback; verify a backtest test covering shared prices passes.
- [x] 3.3 Add a focused backtest test reproducing the 98/102 shared-price loss from the review; verify every armed level in the new ladder has an order after replacement.
- [x] 3.4 Update `docs/backtesting.md` and the dynamic-grid section of `docs/strategies.md` to state that a replaced grid is re-evaluated and shared levels are re-placed; verify the wording matches the regression tests.

## 4. Martingale size configuration validation (#40)

- [x] 4.1 Extract the martingale configuration checks into a shared function used by `Initialize`, the existing-state `OnTick` path, and `OnFill`, covering base quantity, maximum quantity, maximum-versus-base, multiplier, and maximum increases; verify new Go unit tests reject each invalid value during initialization.
- [x] 4.2 Call the shared validation from the existing-state `OnTick` path before `GetGridLevels` or `orderIntents`; verify a test that an initialized bot with `max_order_quantity < base_order_quantity` fails the tick and emits no orders.
- [x] 4.3 Keep `OnFill` validation ahead of the fill-side branch so the existing golden cases with a nil fill still report configuration errors; verify `go test ./internal/strategy` passes.
- [x] 4.4 Add the `max_order_quantity >= order_quantity` check to `app/Http/Requests/Concerns/BuildsStrategyConfiguration.php` using bcmath/`Decimal` string comparison, guarded by the same numeric regex so a non-numeric value keeps its field error instead of throwing; verify Store and Update feature tests fail on `max_order_quantity` for a maximum below the base and for a non-numeric maximum.
- [x] 4.5 Update `docs/strategies.md` and `docs/bot-parameters.md` with the martingale size constraints and verify the documented fields match the request rules.
- [x] 4.6 Add `strategies.json` initialize cases for `max_order_quantity < base_order_quantity`, `size_multiplier <= 1`, and negative `max_increases`; verify `go test ./internal/strategy` passes.

## 5. Martingale size reset after a sell fill (#41)

- [x] 5.1 Change `engine/internal/strategy/martingale.go` so only buy fills increase size, while every sell fill resets `current_quantity` to the base quantity, `increase_count` to zero, and `cap_reached` to false; verify a new buy-then-sell cycle unit test ends at base size and a starting-inventory sell fill also resets to base.
- [x] 5.2 Rewrite `TestMartingaleGridSellPlacesBuyUsingNextSize` as a reset test expecting the adjacent buy at the base quantity, and add sell-at-cap and starting-inventory reset tests; verify `go test ./internal/strategy` passes.
- [x] 5.3 Update the `martingale_increases_after_fill` case in `tests/golden/engine/backtest_replay.json` for the reset final state, and add a strategy golden case for a sell fill if the existing cases do not cover it; verify `go test ./internal/backtest` and `go test ./internal/strategy` pass.
- [x] 5.4 Update `docs/strategies.md` and `docs/bot-parameters.md` to state that every sell fill resets the martingale size and that only buy entries increase it; verify the description matches the new tests.

## 6. Reject dynamic spacing for trailing and martingale (#42f)

- [x] 6.1 Add the dynamic-spacing rejection to `engine/internal/strategy/trailing.go` and `engine/internal/strategy/martingale.go`, returning a message containing `spacing_type` (for example, `spacing_type must be arithmetic or geometric`); verify new Go tests reject dynamic spacing during initialization for both strategies.
- [x] 6.2 Add a plan test asserting these rejections map to the `spacing_type` field; no `fieldFor` change is expected when the messages contain `spacing_type`.
- [x] 6.3 Update `docs/strategies.md` to state that trailing and martingale accept only arithmetic or geometric spacing; verify the documented compatibility matches the Go guards.
- [x] 6.4 Add `strategies.json` cases rejecting `spacing_type=dynamic` for `trailing-grid` and `martingale-grid`; verify `go test ./internal/strategy` passes.

## 7. Integration verification

- [x] 7.1 Run the full Go suite from `engine/` with the repository's required test environment and verify no failures.
- [x] 7.2 Run the full PHP suite and the focused Store/Update validation tests with the documented Docker test overrides and verify no failures.
- [x] 7.3 Run `phpstan` and `pint --dirty` and verify both pass.
- [x] 7.4 Run `npm run build` and `npm run test:js` if any frontend file changed; verify both pass.
- [x] 7.5 Run `openspec validate fix-strategy-review-bugs --strict` and verify the change validates with no warnings.

## Workflow follow-up

- Review the final diff, especially the reviewed golden values and the shared-price regression test.
- Merge only after review and verification.
- Archive the change after the project's review requirements are satisfied.
