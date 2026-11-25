# Design

## Context

See `proposal.md` for motivation and the delta specs for the required behavior.

- The Go engine is the runtime authority. Laravel's four strategy classes are preview-only mirrors.
- All four Go strategies build a center-based ladder with `BuildLevels`, then compute the adjacent level with `adjacentLevel` (buy fills move up one sequence, sell fills move down one). The adjacent level's nominal side comes from the original center and is often the same as the fill side.
- The live runtime's `processPaperFills` fills and closes the whole qualifying batch before the tick loop calls `OnFill` and `placeOrders`. `QualifyingFills` sorts closest-to-market first, but the fix must not depend on that order.
- The backtest deliberately processes fills sequentially, as required by the `backtesting` spec. On a same-candle gap, an opposite order can be deferred while its adjacent level is still open. The live runtime's six-sell result is therefore verified by an opt-in runtime integration test, while the deterministic backtest gets a sequential-candle test that reaches the same end state over time.
- `syncGrid` compares stored grid levels (sequence, price, side) with the strategy's level plans and cancels/recreates the grid on any mismatch. It does not compare open order sides with level sides. `placeOrders` links orders to a level by sequence and inserts the intent's side; it does not enforce the level's side.
- `tests/golden/engine/strategies.json` is a frozen snapshot from the removed PHP runtime. Existing on-fill cases use the innermost level, where the adjacent side already equals the opposite side, so they pass before and after this fix. The martingale on-fill quantities do change.
- `tests/golden/engine/backtest_replay.json` has two cases that exercise outer-level buys and will change: `same_candle_sequential_fills` and `open_order_limit_blocks_second_order`. `tests/Unit/MartingaleGridStrategyTest.php` also asserts the old oversized take-profit quantity.
- The bot detail page currently displays `grid_levels.side`, which is the plan side. After this fix an inner level can hold an order on the opposite side. The controller already has enough data to expose the open order's grid level; the page will show both sides when they differ.
- The local demo DB is stale and its seeded grid uses six total levels while the runtime interprets `level_count: 6` as six per side. It is an environment issue and is not changed here.

## Goals / Non-Goals

**Goals:**

- Every grid strategy's post-fill order is on the side opposite the fill, at the adjacent level.
- A filled level re-arms only after an opposite-side fill at its paired price, independent of fill ordering within a batch.
- Martingale's take-profit order cannot exceed the quantity actually bought.
- Go runtime and PHP previews behave identically, with regression tests for outer levels and multi-fill batches.
- The live six-buy gap is verified as six sells; the sequential backtest equivalent and its same-candle divergence are documented and tested.
- The dashboard shows the actual order side when it differs from the plan side.

**Non-Goals:**

- Changing level generation, spacing, center-side assignment, or the grid schema.
- Changing paper-fill ordering, the live runtime's batch-fill semantics, or the backtest's sequential fill semantics.
- Making a base=0 classic spot grid hold twelve orders after a drop; the expected stable state is six sells funded by the six buys.
- Updating `grid_levels.side` when an opposite order is placed (see Decisions).
- Adding a retry or reconciliation policy for canceled or risk-skipped paired orders.
- Fixing the demo seeder or the local dev database.

## Decisions

### 1. Flip the side at the fill-intent boundary, not in the ladder

Each strategy's `OnFill` emits `opposite(fill.Side)` while keeping the adjacent level's price and sequence. Add a small shared helper in Go and a shared protected helper in the PHP `GeneratesGridLevels` trait rather than repeating conditionals. The PHP strategies keep their private `adjacentLevel` helpers; only the side flip is centralized.

Alternative rejected: re-side the grid levels after fills. That changes the level plans, makes `syncGrid` cancel and recreate the grid, breaks the one-order-per-level linkage, and changes the meaning of the persisted grid.

### 2. Side-aware re-arm in the shared helper

`ApplyFillToLevelState` / `applyFillToLevelState` currently deletes any disarm entry whose `pair` equals the fill price. Change it to delete only when the entry's stored `side` differs from the fill side (the paired fill is the opposite side of the original). This is order-independent and preserves the existing state shape (`{side, pair}`), so no migration is needed.

An entry without a recognisable `side` stays disarmed. No current or historical writer omits `side`; ignoring malformed entries is safer than speculatively re-arming them and reintroducing the same-side bug.

If the paired order is canceled or never placed, the level stays disarmed. This change documents that limitation and adds a regression test; a retry/reconciliation policy is a separate feature.

Alternative rejected: change `QualifyingFills` to process fills in reverse order. It would work for the simple gap case but couples the strategy to fill ordering, fails for mixed batches, and changes a shared paper-fill component for a strategy bug.

### 3. Martingale's adjacent order uses the filled quantity

`current_quantity` continues to grow for future entries, but the sell placed after a buy uses `fill.Quantity`. The old code used the increased `next` quantity for both directions; after the side fix that sell can exceed base inventory, and `placeOrders` skips it, leaving the level disarmed with no order. Using the filled quantity completes the grid cycle and keeps the increased size for the next buy. A buy placed after a sell continues to use the strategy's next size.

Alternative rejected: clamp a sell to available base in `placeOrders`. It hides an invalid intent, changes shared placement/risk behavior, and still leaves the strategy state inconsistent.

### 4. Keep `grid_levels.side` as the plan side; show the actual order side in the dashboard

A filled buy level can now hold a sell order while `grid_levels.side` still says `buy`. This is intentional: the column describes the ladder plan, and `syncGrid` uses it to detect ladder changes. Rewriting it to the order side would make `syncGrid` think the ladder changed and cancel/recreate the grid. Order side remains authoritative for fills, inventory, and trading behavior.

To keep the detail page honest, expose the order's `grid_level_id` in the open-orders payload and show the actual order side beside the plan side when they differ. This is display-only; it does not change `syncGrid`, `placeOrders`, or the schema.

Implementation check: `syncGrid` compares the stored plan's sequence, price, and side; `placeOrders` links an order to a level by sequence and inserts `intent.Side`. Neither validates the order side against the level plan, so no runtime change is needed.

### 5. Direct unit tests for new coverage; narrow, deliberate golden-fixture updates

Add Go and PHP tests for:

- a buy at an outer level placing a sell at the next level above;
- a sell at an outer level placing a buy at the next level below;
- two same-side buys in one batch leaving both levels disarmed;
- a paired opposite fill re-arming only the original level;
- martingale's adjacent quantity equalling the filled quantity;
- a canceled paired order leaving the level disarmed (documented limitation).

Existing golden on-fill cases in `tests/golden/engine/strategies.json` are boundary cases and remain valid. Only the martingale on-fill expected quantities change (and any cap-derived values). Two `tests/golden/engine/backtest_replay.json` cases change:

- `same_candle_sequential_fills`: fills, metrics, and equity are unchanged; `final_state.data.disarmed_levels` gains the still-disarmed 70 and 80 entries.
- `open_order_limit_blocks_second_order`: the second fill becomes a sell at 90 instead of a buy at 90; `buy_count` becomes 1, `sell_count` becomes 1, `final_base_inventory` becomes 0, and `realized_pnl` becomes the buy/sell spread minus fees (9.83 in the current fixture). Update the fills, metrics, equity curve, and final state together.

`tests/Unit/MartingaleGridStrategyTest.php` line 54 changes from `'4'` to `'2'`; `current_quantity` still reaches 4, but the take-profit order uses the filled quantity. The cap test's `'3'` is already the filled quantity and stays.

This is a deliberate exception to the fixture's "do not edit by hand" rule. Record the reason in the change artifacts, keep each edit narrow, and do not edit `tests/golden/README.md` or regenerate unrelated cases.

### 6. Leave the backtest's opposite intent and sequential semantics in place

`engine/internal/backtest/engine.go` already appends a correct opposite intent. After the strategy fix, both intents are identical for buy fills and for simple/dynamic/trailing sells, and `placeIntents` dedupes by price. For a martingale sell, the strategy intent uses the increased entry size while `oppositeIntent` uses the filled quantity; `placeIntents` tries the strategy intent first, so the fallback only matters if risk rejects that larger buy. That divergence is pre-existing; removing the fallback is unnecessary.

The backtest's sequential fill processing is a spec requirement, not a bug to fix here. On a same-candle gap it may defer an opposite order while the adjacent level is still open. Add:

- a focused backtest test with one buy per candle that reaches six sells at 50, 60, 70, 80, 90, and 110, proving the end state without changing replay semantics;
- an opt-in runtime integration test (using the existing `RUNTIME_TEST_DSN` gate) that drives a six-buy gap through the live batch path and asserts the same six sells.

### 7. Dashboard observability only

Expose the open order's `grid_level_id` in `BotController` and render the order side at the matching ladder level. If the plan side is `buy` and the order side is `sell`, show both. This avoids a correct engine looking broken in the UI. No route, policy, schema, or trading behavior changes.

## Risks / Trade-offs

- **Grid level side differs from order side** → `grid_levels.side` remains the plan attribute; no runtime path validates order side against it. The dashboard now shows both sides. Add a test if a future query starts relying on one side.
- **Frozen golden fixture exception** → two `backtest_replay.json` cases plus martingale quantities in `strategies.json` and one PHP assertion. Document the reason, keep edits narrow, and run the full Go and PHP suites. Do not edit `tests/golden/README.md`.
- **A canceled or risk-skipped paired order leaves a level disarmed** → this is existing behavior for any skipped opposite order. The spec now states the limitation and a regression test records it. Do not add retries in this change; a retry/reconciliation policy would be a separate decision.
- **Trailing/dynamic recentering** → recentering can cancel and rebuild the ladder; side-aware re-arm still applies to fills, but a recenter in the same tick may leave a placement gap. This is pre-existing and out of scope; document the canceled-pair behavior in the regression test.
- **Backtest divergence** → the live batch can place six sells while a same-candle sequential replay may defer some. Document this in the `backtesting` delta, test the sequential-candle equivalent, and run the opt-in runtime test for the live acceptance.
- **Rollback** → code-only revert; the state shape is unchanged, so old code can read new state. Rolling back reintroduces the bug but does not corrupt data.
