## ADDED Requirements

### Requirement: Parameter sweep creation
The system SHALL create a parameter sweep that runs one backtest for every combination of selected center prices, spacing values, and level counts against one bot, timeframe, and date range. A sweep SHALL create between 2 and the configured maximum runs, SHALL reject duplicate or invalid values, and SHALL apply only those three parameters over each run's immutable configuration snapshot.

#### Scenario: Creates the cartesian product
- **WHEN** an owner submits two center prices, two spacing values, and two level counts for one bot
- **THEN** the system creates eight pending backtest runs grouped under one sweep

#### Scenario: Overrides only swept fields
- **WHEN** a sweep run is created from a bot whose strategy sets different values
- **THEN** the run snapshot uses the swept center price, spacing value, and level count while preserving every other strategy, risk, exit, and period field

#### Scenario: Rejects a set with one combination
- **WHEN** an owner submits values that produce only one run
- **THEN** the request is rejected without creating a sweep or any run

### Requirement: Sweep validation and limits
A sweep SHALL use a grid strategy, positive decimal center prices and spacing values, integer level counts from 1 to 50, and a total run count no greater than the configured sweep maximum. Duplicate values within one dimension SHALL be rejected. The sweep and its runs SHALL belong to the authenticated owner.

#### Scenario: Accepts a valid grid sweep
- **WHEN** an owner submits unique positive values and level counts within range for a grid bot
- **THEN** the sweep and its runs are created for that owner

#### Scenario: Rejects an oversized sweep
- **WHEN** the cartesian product exceeds the configured maximum
- **THEN** the request is rejected with a validation error and no runs are created

#### Scenario: Rejects a non-grid strategy
- **WHEN** an owner selects a bot whose strategy is not a grid strategy
- **THEN** the request is rejected with a validation error

### Requirement: Sweep grouping and results
Each sweep run SHALL retain its own immutable snapshot, status, progress, end reason, metrics, and equity curve. The sweep SHALL expose its runs with their swept values, allow active runs to be polled, and allow two to four completed runs to use the existing comparison view.

#### Scenario: Groups runs under the sweep
- **WHEN** a sweep is created
- **THEN** every generated run stores the sweep identifier and its swept values and appears on the sweep detail page

#### Scenario: Reports active progress
- **WHEN** some sweep runs are pending or running
- **THEN** the sweep detail response reports their status and progress for polling

#### Scenario: Compares completed runs
- **WHEN** an owner selects two to four completed runs from the same sweep
- **THEN** the existing comparison view accepts them
