# Design

## Context

See `proposal.md` for the motivation. The current runtime has two paths for a tick:

- New state: `Runtime.decide` calls `impl.Initialize`, then calls `impl.OnTick` with the initialized state.
- Existing state: `Runtime.decide` calls `impl.OnTick` directly.

Every built-in strategy's `OnTick` already has a new-state branch that calls its own `Initialize` and then emits its initial order intents. The runtime's pre-initialization is therefore redundant, and for `dynamic-grid` it is harmful: `Initialize` records `last_recalculated_at`, so the subsequent `OnTick` takes the interval-guard path and returns levels without orders.

`DynamicGrid.OnTick`'s new-state branch is the only path that emits the initial ladder before the first recalculation. Plan preview is unaffected because it uses the strategy/plan evaluator directly.

## Goals / Non-Goals

**Goals:**

- A new bot produces its initial order intents on its first tick for every built-in strategy, including `dynamic-grid`.
- The runtime has one initialization path: the strategy's normal tick evaluation.
- Existing-state ticks, recalculation timing, and persisted state remain unchanged.
- Regression coverage directly exercises the runtime decision for a new dynamic grid.

**Non-Goals:**

- Changing dynamic spacing calculation, the recalculation interval, or level generation.
- Changing Laravel preview or bot-creation behavior.
- Changing paper-fill ordering, order placement, risk checks, or live-trading gates.

## Decisions

### 1. Let `OnTick` own initialization

`Runtime.decide` will call `impl.OnTick(input)` for both new and existing state. The strategy's `OnTick` new-state branch already calls `Initialize` and then generates orders using the initialized state.

Alternative rejected: special-case `dynamic-grid` in the runtime by setting `last_recalculated_at` to zero or by calling `OnTick` twice. That duplicates strategy knowledge in the runtime, leaves a second initialization path, and makes future strategies easy to get wrong.

Alternative rejected: add an order-generation call after `Initialize` only for dynamic grids. That would work for this bug but keeps two tick paths and risks divergence from the other strategies.

### 2. Test at the runtime decision boundary

Add an internal runtime test in `package runtime` that calls `decide` with a new `dynamic-grid` input and asserts that the decision contains the initial order intents and initialized state. This is the narrowest test that fails on the current code and passes with the fix; it requires no database or network. Add a companion assertion that an already-initialized state takes the normal tick path without reinitializing.

The existing opt-in PostgreSQL runtime integration test continues to cover end-to-end tick persistence and can be run with `RUNTIME_TEST_DSN` when a test database is available.

### 3. Keep the fix scoped to the runtime

No strategy implementation changes are needed: all four `OnTick` implementations already handle new state. No state schema, configuration, or API changes are required. A rollback is a one-line runtime revert; existing persisted state remains readable.

## Risks / Trade-offs

- **A future strategy's `OnTick` might not handle new state** → The strategy contract and tests must require it. The new runtime test covers the dynamic-grid case; existing strategy tests cover the other new-state branches.
- **Error behavior changes subtly** → Errors from `Initialize` now surface from `OnTick` instead of the runtime's pre-initialization call. All strategies return the same error unchanged, so the bot failure path remains the same.
- **Integration test availability** → The database-backed runtime test is opt-in. The unit-level decision test provides deterministic coverage in the default suite; run the integration test when a migrated test database is available.
