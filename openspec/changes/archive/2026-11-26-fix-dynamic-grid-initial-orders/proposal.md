# Proposal

## Why

A newly started `dynamic-grid` bot initializes its strategy state and grid on the first tick but places no orders. `Runtime.decide` pre-initializes new state, then calls `OnTick`; because initialization records the recalculation timestamp, the dynamic strategy's interval guard suppresses the initial ladder until `recalculation_interval_seconds` elapses. The bot therefore contradicts its preview plan and can stay idle for minutes with a long configured interval.

## What Changes

- Make first-tick evaluation the strategy's responsibility: for new state, the runtime calls `OnTick` directly instead of pre-initializing and then ticking an already-initialized state.
- All four built-in strategies already handle new state in `OnTick` by initializing and emitting their initial order intents, so the runtime change covers simple, dynamic, martingale, and trailing grids consistently.
- Add regression coverage proving a new `dynamic-grid` bot emits its initial ladder on the first tick, and that existing-state recalculation behavior is unchanged.
- Update the `strategy-engine` spec so a new dynamic grid's first tick is required to place the initial ladder rather than wait for the first recalculation.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `strategy-engine`: the strategy lifecycle and dynamic-grid requirements now require the first tick of a new bot to initialize and emit its initial orders in the same decision.

## Impact

- Go engine: `engine/internal/runtime/tick.go` and engine tests.
- No Laravel, API, schema, configuration, or live-trading behavior changes.
- Existing bots with persisted state keep their current tick path; only new state changes.
