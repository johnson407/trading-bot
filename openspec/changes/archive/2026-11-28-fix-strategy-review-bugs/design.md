# Design

## Context

See `proposal.md` for motivation. The relevant current behavior is:

- `engine/internal/strategy/dynamic.go` emits order intents only on the first tick and on ticks that recalculate spacing. The non-due branch returns levels with no orders.
- `engine/internal/backtest/engine.go` special-cases the first candle by calling `Initialize` and then `OnTick` with the initialized state. Dynamic then takes its non-due branch and emits no orders.
- Both live and backtest replace a grid by canceling all open orders. The runtime and backtest re-evaluate after replacement, but the second dynamic evaluation also hits the non-due branch. The backtest overwrites the first decision with that empty result.
- `engine/internal/strategy/martingale.go` increases size on any fill and only validates the maximum quantity inside `OnFill`; an existing-state `OnTick` validates neither the maximum nor the multiplier before placing orders. Laravel validates that the maximum is positive but not that it is at least the base quantity.
- `trailing.go` and `martingale.go` accept `spacing_type=dynamic` and treat it as geometric spacing.

The change must preserve the existing strategy state format, the Go-only evaluator boundary, the close-only backtest fill model, and all live-trading and risk gates.

## Goals / Non-Goals

**Goals:**

- Make dynamic-grid order intent generation independent of whether a tick recalculates spacing.
- Make the backtest first candle use the same tick path as live execution.
- Ensure a replaced grid's new ladder gets orders, including prices shared with the old ladder.
- Reject invalid martingale size configurations before orders are placed and before a bot is saved.
- Make martingale size increase only on buy entry fills and reset after every sell fill.
- Reject dynamic spacing for trailing and martingale strategies.
- Keep live, backtest, preview, and persisted state behavior consistent.

**Non-Goals:**

- Order placement priority under constrained capital or open-order limits (review item #42a).
- Orphaned inventory, gap fill pricing, exchange precision/minimum enforcement in paper/backtest, `initial_spacing` cleanup, trailing disarmed-level pruning, or volatility-window semantics (#42b-e, #42g, #42h).
- Data repair for existing bots with an invalid martingale maximum.
- Any change to the live gate, risk limits, queueing, Horizon, or backtest caps.

## Decisions

### 1. Dynamic replenishment belongs in the strategy, not the runtime

Add order generation to dynamic's non-due branch, using the same `orderIntents` path as simple, trailing, and martingale. Do not teach the runtime to synthesize orders at active levels.

**Why:** the strategy owns intent generation, and all other strategies already behave this way. A runtime special case would diverge between live and backtest and would need to duplicate arming, open-order, and sequence rules. It would also repeat the mistake that the archived `fix-dynamic-grid-initial-orders` change removed.

**Alternative considered:** have `syncGrid` re-place orders after replacement. Rejected because `syncGrid` does not know which levels are armed or which order quantities the strategy wants.

### 2. The backtest first candle uses the normal tick path

Remove the `index == 0` initialize-then-tick special case. On the first candle, pass a new state to `OnTick`; the strategy initializes internally and emits its initial intents in the same decision.

**Why:** this is exactly how live `tick.go` handles a new bot, and the strategy-engine spec already requires the first tick to be evaluated through the normal path. Keeping a backtest-only initialization step creates a second lifecycle to maintain.

**Alternative considered:** call `Initialize` and then manually copy its order intents into the first decision. Rejected as duplicated lifecycle logic that would drift again.

### 3. Grid replacement relies on re-evaluation, with a first-pass fallback

After a replacement cancels open orders, the runtime and backtest re-evaluate the strategy against the new ladder. With dynamic replenishment, the second evaluation emits orders for every armed level that now lacks one. In the backtest, if the second evaluation returns no orders, retain the first evaluation's orders for that candle rather than replacing them with an empty list.

**Why:** the first evaluation can legitimately skip a level because it had an open order. After replacement cancels that order, only a fresh evaluation can produce the correct full ladder. Reusing the first evaluation alone would still lose shared-price levels. The fallback protects against a strategy that emits no second-pass orders, but it is not the primary fix.

**Test consequence:** the acceptance test must assert that prices shared by the old and new ladders receive orders. A test that only checks newly added prices would pass even with the original bug. Extract the backtest's first-pass/second-pass order merge into a small helper and unit-test the fallback directly, because the engine constructs its strategy internally and the normal second pass will return replenished orders once decision 1 lands.

### 4. Martingale validation is shared by initialization, resumed ticks, and fill handling

Extract the martingale configuration checks into one function used by `Initialize`, the existing-state `OnTick` path before `GetGridLevels` or `orderIntents`, and `OnFill`. A resumed bot with stored invalid values must fail the tick before any order is placed. `Initialize` rejects invalid values before any order intent is emitted; `OnFill` keeps validating before the fill-side branch so the existing golden cases with a nil fill still report configuration errors. The checks are:

- `base_order_quantity > 0`
- `max_order_quantity > 0` and `max_order_quantity >= base_order_quantity`
- `size_multiplier > 1`
- `max_increases >= 0`

Laravel adds the missing base/maximum relationship check in the shared strategy-configuration trait. It uses bcmath/`Decimal` string comparison after the same numeric-regex guard as the request rules, never a float cast; malformed input keeps its field error instead of throwing. Request-level `size_multiplier` and `max_increases` rules already exist; the Go checks remain authoritative for stored snapshots and backtests.

**Why:** validation at creation gives a field-level error, while validation in `Initialize` and on resumed ticks protects stored configurations and backtest snapshots that bypass the form. The plan endpoint maps size-related strategy error reasons to fields, so the Go validation alone would surface in the form; the PHP check makes the failure immediate and keeps the error on the correct field.

**Alternative considered:** only validating in `OnFill` (current behavior). Rejected because the bot places orders before the first fill and can enter a permanent failure loop.

### 5. Martingale increases only on buy fills and resets on every sell fill

In `OnFill`, branch on the fill side before applying the increase:

- Buy fill: keep the existing multiplier/cap/increase-count behavior and emit a take-profit sell using the filled quantity.
- Sell fill: do not increase. Reset `current_quantity` to the base quantity, `increase_count` to zero, and `cap_reached` to false. The adjacent buy uses the reset base quantity.
- No reset event is emitted; the reset is represented by the persisted state.

**Why:** in this long-only grid, buys are entries and sells are exits. Any sell fill either completes a take-profit for a filled buy or sells starting inventory; in both cases the next entry should start from the base size. Resetting on every sell avoids a second state machine for "which sell completes which buy" and matches the review's recommendation.

**Alternative considered:** reset only when no other sell orders remain, or only when the sell re-arms a previously filled buy. Both require per-cycle inventory tracking that the strategy does not have today, and the first can place an oversized intermediate entry while another take-profit is still open. If short entries are introduced later, this assumption must be revisited.

### 6. Dynamic spacing is rejected at the strategy boundary for trailing and martingale

Mirror simple's existing spacing-type guard in trailing and martingale. Do not rely on the form to omit the value: stored snapshots and direct plan requests must also fail. Return a reason containing the literal `spacing_type` (for example, `spacing_type must be arithmetic or geometric`) so the plan endpoint's `fieldFor` maps the error to the spacing-type field instead of its generic `spacing_value` fallback.

**Why:** treating dynamic as geometric can generate valid-looking but nonsensical ladders. The strategy is the last line of defense before orders are placed.

### 7. Golden fixtures are updated by review, not by bulk regeneration

Update `tests/golden/engine/strategies.json` and `tests/golden/engine/backtest_replay.json` after the behavior changes, and verify each changed case against the intended behavior. The dynamic backtest case will change materially because the first candle now places orders. The martingale backtest case should change only in final sizing state if the existing sell remains the terminal fill. Review note for `dynamic_grid_recalculates`: adding the ladder on the first candle also removes one due-recalculation pass from that candle, so the reviewed final `recalculation_count` is 4 rather than 5; the remaining two candles still recalculate on both passes.

**Why:** golden tests are the main regression contract for deterministic replay. Blindly accepting new output would hide unintended changes.

## Risks / Trade-offs

- **Existing invalid martingale bots fail closed.** → Surface a clear initialization or tick error, document the new constraint, and require the user to edit the bot. No silent risk-limit increase.
- **Backtest results change for dynamic strategies.** → Review the dynamic golden case and any strategy comparison fixtures; call out the change in the proposal and docs.
- **Dynamic may retry rejected orders on every tick.** → This is the intended replenishment behavior; existing risk checks and failure reporting still reject invalid orders, and tests cover that no duplicate order is emitted for a live order.
- **The sell-reset rule assumes sells are exits.** → Document the assumption in the martingale requirement and design. Revisit if short entries are added.
- **The first-pass fallback is not sufficient by itself.** → Make the shared-price replacement test the acceptance criterion; do not rely on the fallback to claim #39 is fixed.
- **Golden fixture churn can mask regressions.** → Review every changed value and keep the scenario names meaningful.
- **State compatibility.** → No state-format change. Existing martingale states with `current_quantity`, `increase_count`, and `cap_reached` remain readable; a sell fill may reset them to base values.

## Migration Plan

1. Land the strategy and backtest changes with updated unit, golden, and runtime/backtest tests.
2. Add the Laravel validation and feature tests; no database migration.
3. Update the two specs and `docs/strategies.md`, `docs/backtesting.md`, and `docs/bot-parameters.md`.
4. Run the Go suite, PHP suite, static analysis, formatting, frontend checks if needed, and `openspec validate --strict`.
5. Deploy with the normal image build. Bots with invalid martingale configurations will fail initialization (new or reset state) or their next existing-state tick and surface an error until edited.
6. Rollback by reverting the application image. Persisted strategy state remains valid because no fields are added or removed.

## Open Questions

None for this change. The remaining review items are explicitly deferred to separate changes.
