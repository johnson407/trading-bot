# Spec Delta

## ADDED Requirements

### Requirement: Create paper bot from backtest results

Completed backtest run detail pages and completed sweep run rows SHALL offer a create-paper-bot action when the run is usable by the viewer. The run and sweep run resources SHALL expose a `can_create_bot` flag that matches the action's availability. The action SHALL open `bots.create` with the run ID as `source_backtest_run_id`.

#### Scenario: Completed run exposes the action
- **WHEN** the owner views a completed run with a usable snapshot and available strategy
- **THEN** the run detail page offers a create-paper-bot action targeting that run

#### Scenario: Incomplete or failed run has no action
- **WHEN** a run is pending, running, or failed
- **THEN** its detail page does not offer the create-paper-bot action

#### Scenario: Unsupported snapshot or exchange has no action
- **WHEN** a completed run has an unusable snapshot, an unavailable strategy, a disallowed exchange, or an invalid symbol
- **THEN** `can_create_bot` is false and the action is not offered

#### Scenario: Sweep run row exposes the action
- **WHEN** the owner views a completed sweep run row with a usable source
- **THEN** the row offers a create-paper-bot action targeting that specific run

#### Scenario: Incomplete sweep row has no action
- **WHEN** a sweep run is pending, running, or failed
- **THEN** its row does not offer the create-paper-bot action

#### Scenario: Another user's run is not exposed
- **WHEN** a run belongs to another user
- **THEN** `can_create_bot` is false and no action is offered

#### Scenario: Resource flags match the action
- **WHEN** the run and sweep run resources are rendered
- **THEN** `can_create_bot` is true exactly when the action is available
