# Proposal

## Why

Measured dynamic grids replace their entire ladder whenever a volatility recalculation changes spacing. On 2026-11-27, bot 5 produced 203 stopped grids and 1,216 canceled orders in 24 hours for 4 fills; bot 3 produced 89 stopped grids and 540 canceled orders in 7.5 hours for 0 fills. Every recalculation changes the decimal spacing slightly, exact level matching fails, and the whole ladder is canceled and rebuilt.

Tuning the recalculation interval is a blunt lever: it reduces how often spacing is reconsidered but does not stop a single small volatility change from repricing every level. A spacing hysteresis keeps the current ladder when the new spacing is close to the current spacing, while still allowing larger volatility changes to reprice.

## What Changes

- Add an optional `spacing_hysteresis_percent` dynamic-grid configuration value with a default of `20` and a validated range of `0` to `90`.
- During a due recalculation, keep the current spacing when the candidate spacing differs from it by less than the configured percentage. Persist the recalculation timestamp so the strategy waits for the next interval instead of retrying every tick.
- Always adopt the candidate spacing when the difference meets or exceeds the threshold or when the threshold is `0`. Missing, non-numeric, or non-positive persisted spacing fails the tick under the existing state-persistence rules rather than silently repricing.
- Expose the value through Laravel bot validation and configuration building, the Vue create/edit forms, and the documented dynamic-grid parameters.
- Correct the documented dynamic-grid flow so it describes full-ladder replacement when spacing changes beyond the threshold, rather than selective order retention that runtime does not perform.
- Add Go strategy tests for adopting, skipping, boundary values, and disabled (`0`) hysteresis, plus Laravel validation and documentation coverage.

Existing bots without the key use the `20` percent default, so their next recalculation may keep the current ladder instead of repricing. Setting `0` preserves the old always-adopt behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `strategy-engine`: the Dynamic grid strategy gains a configurable spacing hysteresis that retains current spacing for small volatility changes.

## Impact

- Go: `engine/internal/strategy/dynamic.go`, `engine/internal/plan/plan.go`, dynamic strategy tests, backtest/golden fixtures, and a deterministic no-replacement test.
- Laravel: bot create/update/preview validation and `BuildsStrategyConfiguration`.
- Frontend: `resources/js/Pages/Bots/Create.vue`, `Edit.vue`, and `resources/js/Support/botSetup.js`.
- Docs: `docs/strategies.md`, `docs/bot-parameters.md`, and a new `tests/Unit/StrategyDocsTest.php`.
- Observability: runtime tick telemetry should show fewer `grids_replaced`, `orders_canceled`, and replacement placement counts; no new telemetry fields are required.
- Compatibility: existing bots without the key use the 20 percent default. Their first save also adds a configuration key, which is treated as a structural change and resets strategy state before the next run; bots are editable only while idle or stopped.
- No database migration, API break, live-gate change, or risk-control change.
