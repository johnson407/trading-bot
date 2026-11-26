# Tasks

## 1. Regression coverage

- [x] 1.1 Add an internal runtime test in `engine/internal/runtime/decide_internal_test.go` (package `runtime`) that calls `decide` with a new `dynamic-grid` input and asserts the decision contains the initial order intents, an initialized state, and levels around the configured center. Add a companion case proving an already-initialized state is not reinitialized. Verify the new test fails on the current `decide` implementation with `go test ./internal/runtime -run TestDecideNewDynamicGrid`.
- [x] 1.2 Run the focused runtime test and record the failing result before changing production code. Verify the failure is the missing initial orders, not a setup or type error.

## 2. Runtime fix

- [x] 2.1 Change `Runtime.decide` in `engine/internal/runtime/tick.go` to call `impl.OnTick(input)` for new and existing state, removing the pre-initialization path. Verify `go test ./internal/runtime -run TestDecideNewDynamicGrid` passes and the existing runtime unit tests still pass.
- [x] 2.2 Run the full Go suite with `go test ./...` from `engine/` (or the equivalent Docker Go command if the host has no Go toolchain) and verify all non-opt-in packages pass.

## 3. Integration and validation

- [x] 3.1 Run the opt-in PostgreSQL runtime integration test with `RUNTIME_TEST_DSN` when a migrated test database is available, and verify the existing runtime tick behavior still passes. If no test database is available, record that the default suite skips it.
- [x] 3.2 Run `openspec validate fix-dynamic-grid-initial-orders --strict` and verify the proposal, spec delta, design, and tasks validate without warnings.
- [x] 3.3 Review `git diff` and `git diff --check`, confirm no unrelated files or live-trading settings changed, and verify the change is limited to the runtime path, its regression test, and the OpenSpec artifacts.

## Workflow follow-up

- Archive the change after implementation, review, and verification pass.
- Re-run `openspec validate` after archive and confirm the `strategy-engine` main spec contains the initial-evaluation requirement.
