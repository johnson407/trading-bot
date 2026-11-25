# Tasks

## 1. Shared re-arm semantics

- [x] 1.1 Add Go regression tests in a new `engine/internal/strategy/grid_fill_state_test.go` (the package currently only has `golden_test.go`): a buy at 80 followed by a buy at 90 keeps 80 disarmed, a later sell at 90 re-arms 80, and an entry without a recorded side stays disarmed. Name the tests explicitly and verify they fail on the current helper with `make -C engine test` or `go test ./internal/strategy -run TestApplyFillToLevelState`.
- [x] 1.2 Add mirrored PHP tests in `tests/Unit/SimpleGridStrategyTest.php` for the same-side and opposite-side re-arm sequence, using the documented Pest filter. Verify they fail on the current trait.
- [x] 1.3 Implement side-aware re-arm in `engine/internal/strategy/grid.go` and `app/Strategies/Concerns/GeneratesGridLevels.php`: remove a disarm entry only when its stored side differs from the fill side; entries without a recorded side stay disarmed. Verify 1.1 and 1.2 pass and the existing re-arm test still passes.
- [x] 1.4 Document and test the unfilled-pair limitation: after the paired order is canceled, the original level stays disarmed and receives no replacement order. Keep this regression test limited to the documented behavior; do not add retry logic.

## 2. Simple grid opposite-side fills

- [x] 2.1 Add Go tests for simple-grid: a buy at outer level 80 places a sell at 90, a sell at 120 places a buy at 110, and a batch that fills all six buy levels produces sells at 50, 60, 70, 80, 90, and 110 for a center of 100, spacing 10, and no base inventory. Assert all six buy levels stay disarmed, the next `OnTick` emits no buy intents at 40 through 90, and any upper sell intents (120 through 160) are understood to be blocked by inventory at runtime rather than by the strategy. Verify the new tests fail on the current strategy.
- [x] 2.2 Implement `opposite(fill.Side)` in `engine/internal/strategy/simple.go` while keeping the adjacent price/sequence and `reason: strategy.opposite`. Verify the 2.1 tests and the existing boundary cases `TestGoldenStrategies/simple-grid/on_fill_buy_places_adjacent_sell` and `.../on_fill_sell_uses_adjacent_level_side` pass.
- [x] 2.3 Add mirrored PHP tests in `tests/Unit/SimpleGridStrategyTest.php` for the outer-level buy and sell plus the six-buy sequence. Verify they fail on the current strategy.
- [x] 2.4 Implement the same side flip in `app/Strategies/SimpleGridStrategy.php`. Verify all simple-grid PHP tests pass.

## 3. Dynamic grid opposite-side fills

- [x] 3.1 Add Go tests for dynamic-grid with two levels per side: a buy at 80 places a sell at 90, and a sell at 120 places a buy at 110, using the stored spacing from initialized state. Verify they fail on the current strategy.
- [x] 3.2 Implement `opposite(fill.Side)` in `engine/internal/strategy/dynamic.go` without changing spacing recalculation, level retention, or cancel behavior. Verify the 3.1 tests and existing dynamic-grid tests pass.
- [x] 3.3 Add mirrored PHP tests in `tests/Unit/DynamicGridStrategyTest.php` and implement the same flip in `app/Strategies/DynamicGridStrategy.php`. Verify all dynamic-grid PHP tests pass.

## 4. Martingale grid opposite-side fills and take-profit size

- [x] 4.1 Add Go tests for martingale-grid with two levels per side and explicit state: a buy at 80 places a sell at 90 for the filled quantity; a second buy at 90 places a sell at 110 for that fill's quantity rather than the increased next size; and a sell at 120 places a buy at 110 using the strategy's next size. State the expected `current_quantity` and expected order quantity in each test so the increased-size semantics are unambiguous. Verify they fail on the current strategy.
- [x] 4.2 Implement `opposite(fill.Side)` in `engine/internal/strategy/martingale.go`; use `fill.Quantity` for the sell after a buy and the increased `next` for the buy after a sell. Keep `current_quantity`, `increase_count`, cap events, and the Martingale spec behavior unchanged. Verify the 4.1 tests pass.
- [x] 4.3 Update only the affected martingale on-fill expected `quantity` values in `tests/golden/engine/strategies.json` (state, side, price, sequence, cap, and unrelated strategy cases stay untouched). Update `tests/Unit/MartingaleGridStrategyTest.php` line 54 from `'4'` to `'2'`; leave the cap test's `'3'` unchanged. Record the intentional frozen-snapshot exception in the change artifacts, not in `tests/golden/README.md`. Verify `TestGoldenStrategies` and the martingale PHP tests pass.
- [x] 4.4 Implement the same side/quantity rules in `app/Strategies/MartingaleGridStrategy.php`. Verify all martingale PHP tests pass.

## 5. Trailing grid opposite-side fills

- [x] 5.1 Add Go tests for trailing-grid with two levels per side and a market inside the trailing distance: a buy at 80 places a sell at 90, and a sell at 120 places a buy at 110. Verify they fail on the current strategy.
- [x] 5.2 Implement `opposite(fill.Side)` in `engine/internal/strategy/trailing.go` without changing recentering, order retention, or cancel behavior. Verify the 5.1 tests and existing trailing-grid tests pass.
- [x] 5.3 Add mirrored PHP tests in `tests/Unit/TrailingGridStrategyTest.php` and implement the same flip in `app/Strategies/TrailingGridStrategy.php`. Verify all trailing-grid PHP tests pass.
- [x] 5.4 Add the canceled-paired-order regression test for trailing and dynamic grids: a recenter/cancel does not silently re-arm the filled level, and the documented "stays disarmed until state reset or reconciliation" behavior is asserted.

## 6. Backtest, dashboard, and documentation integration

- [x] 6.1 Add a focused Go backtest test with one buy per candle that reaches six sells at 50, 60, 70, 80, 90, and 110. This proves the sequential replay end state without changing replay semantics; do not add a new golden fixture.
- [x] 6.2 Update `tests/golden/engine/backtest_replay.json`:
  - `same_candle_sequential_fills`: add the still-disarmed 70 and 80 entries to `final_state.data.disarmed_levels`; fills, metrics, and equity remain unchanged.
  - `open_order_limit_blocks_second_order`: the second fill becomes a sell at 90 instead of a buy at 90; update the fills, `buy_count`, `sell_count`, `final_base_inventory`, `realized_pnl`, equity curve, and final state together.
  - Run `go test ./internal/backtest -run TestGoldenReplay` and confirm no other case changes. Record the frozen-fixture exception in the change artifacts.
- [x] 6.3 Add an opt-in runtime integration test (gated by the existing `RUNTIME_TEST_DSN`) that sets up a six-level-per-side bot with no base inventory, gaps the market below all six buys, and asserts the six sells at 50, 60, 70, 80, 90, and 110, the persisted six buy-level disarm pairings, and no buys above the market on the next tick. This is the live six-sell acceptance.
- [x] 6.4 Verify the `backtesting` delta documents the same-candle deferral and that the `grid-trading` multi-fill scenario is scoped to the live batch behavior. Do not change the backtest's sequential fill processing.
- [x] 6.5 Dashboard: expose `grid_level_id` in `BotController`'s open-orders payload and render the actual order side beside the plan side in `resources/js/Pages/Bots/Show.vue` when they differ. Add a focused feature test for the payload/behavior. No route, policy, schema, or trading change.
- [x] 6.6 Review `engine/README.md` and `docs/bot-parameters.md` for wording that implies a level's side never changes after a fill. Clarify that the adjacent level receives the opposite order while `grid_levels.side` remains the center-based plan side; run any documentation tests that reference the edited files.
- [x] 6.7 Confirm `syncGrid` and `placeOrders` need no code change: the level is linked by sequence, the order side comes from the intent, and no runtime path validates order side against the level plan. Record the verification in the change notes.

## 7. Final verification

- [x] 7.1 Run the full Go suite with `make -C engine test` and confirm all strategy, runtime (non-DB), and backtest packages pass.
- [x] 7.2 Run the full PHP suite with the documented Docker Compose environment overrides (`composer test`) and confirm it passes.
- [x] 7.3 Run `phpstan` at level 5 and `pint --dirty`; fix only issues introduced by this change.
- [x] 7.4 Run the opt-in runtime integration test with `RUNTIME_TEST_DSN` against the test database and confirm the six-buy gap produces six sells. If the database is unavailable, record that the default suite skips this test.
- [x] 7.5 Run `git diff --check`, confirm no unrelated files or live-trading flags changed, and review the final diff for the four Go strategies, four PHP mirrors, both shared helpers, Go/PHP tests, both golden fixtures, the PHP martingale assertion, the dashboard payload/page, and the docs.
- [x] 7.6 Run `openspec validate fix-grid-opposite-fill-sides --strict` and confirm all four delta specs validate.

## Workflow follow-up

- Archive the change after implementation, review, and the full verification suite pass.
- Re-run `openspec validate` after archive and confirm the main `grid-trading`, `strategy-engine`, `backtesting`, and `trading-dashboard` specs contain the updated requirements.
