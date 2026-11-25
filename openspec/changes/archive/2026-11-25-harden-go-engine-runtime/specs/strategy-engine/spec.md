## ADDED Requirements

### Requirement: Go is the only strategy evaluator

After this change the Go engine SHALL be the only component that runs strategy initialization, tick evaluation, and setup plan evaluation. Laravel SHALL NOT keep PHP strategy runtime classes or bindings for Static, Dynamic, Trailing, or Martingale grids, and SHALL NOT call PHP `onTick` to build a preview or a saved grid.

#### Scenario: PHP strategy runtime is removed

- **WHEN** the change is applied
- **THEN** Laravel no longer registers or invokes PHP strategy implementations for ticks or plan preview

#### Scenario: Preview uses the same evaluator as ticks

- **WHEN** a setup is previewed
- **THEN** the levels are produced by the Go strategy implementation that would run for a bot with that configuration
