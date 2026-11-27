# Tasks

## 1. Engine spacing hysteresis

- [x] 1.1 Add `spacing_hysteresis_percent` parsing to the dynamic strategy with a `20` default and inclusive `0`-`90` validation. Call the shared helper from both `Initialize` and `OnTick` before the interval guard so preview and first-tick validation fail early. Verify unit tests reject negative, over-90, and non-numeric values with a field-specific error.
- [x] 1.2 Apply hysteresis in `DynamicGrid.OnTick` after the candidate spacing is calculated and clamped. Compare with exact decimal cross-multiplication: `abs(candidate-current) * 100 >= threshold * current`; retain current spacing below the threshold, adopt at or above it, and still advance `last_recalculated_at` and `recalculation_count` on a skip. Return the current levels and no cancel intents on a skip. Verify table tests cover within-threshold, exactly-at-threshold, above/below, fractional thresholds, zero-threshold, default, and missing/non-numeric/zero/negative current spacing failing closed.
- [x] 1.3 Set explicit `spacing_hysteresis_percent: 0` in existing dynamic strategy tests and golden fixtures that assert the old always-adopt sequence. Check `tests/golden/engine/backtest_replay.json` case `dynamic_grid_recalculates` and the dynamic cases in `tests/golden/engine/strategies.json`; add focused cases for the default and threshold behavior. Add a deterministic no-replacement assertion that a skipped recalculation yields identical levels, no cancel intents, and no grid replacement or order cancellation.
- [x] 1.4 Add `spacing_hysteresis_percent` to `engine/internal/plan/plan.go` `fieldFor` before the generic `spacing` case and wrap non-numeric parse errors with the field name. Verify a bad value is reported against the hysteresis field, not `spacing_value`.

## 2. Laravel configuration path

- [x] 2.1 Add the field to `StoreBotRequest`, `UpdateBotRequest`, and `PreviewBotRequest` as nullable numeric `0`-`90`, and store it in `BuildsStrategyConfiguration` with a `20` default. Verify feature tests cover create, update, preview, inclusive bounds, and rejection.
- [x] 2.2 Include the field in `PreviewBotRequest::concreteValues()` so the quick-setup preview response echoes it. Verify an invalid hysteresis value fails preview before initialization, and a valid preview still returns a plan.

## 3. Frontend and documentation

- [x] 3.1 Add the field to the advanced preview payload in `botSetup.js`, the Create/Edit preview watchers, and the `isDynamic` form blocks with a `20` default, numeric input, and validation error display. Verify `npm run build` and `npm run test:js` pass, including a payload test.
- [x] 3.2 Document the key, default, range, percentage semantics, clamp interaction, skip/adopt behavior, `0` rollback lever, and corrected full-ladder replacement flow in `docs/strategies.md` and `docs/bot-parameters.md`. Correct the same selective-retention claim in the `trailing-grid` section. Add `tests/Unit/StrategyDocsTest.php` asserting the key, default, range, rollback lever, and full-ladder wording, and verify the docs tests pass.

## 4. Integration verification

- [x] 4.1 Run the full Go suite with `RUNTIME_TEST_DSN`, the focused and full PHP suites, the frontend build and JS tests, and `openspec validate dynamic-grid-spacing-hysteresis --strict`.
- [x] 4.2 Confirm the tick-telemetry comparison plan is documented: compare `grids_replaced`, `orders_canceled`, `orders_placed`, and `paper_fills` for the dynamic bots over a comparable window after deployment.

## Workflow follow-up

- Review the completed change before archiving.
- Archive the change after implementation and verification.
