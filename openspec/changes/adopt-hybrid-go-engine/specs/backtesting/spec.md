# Spec Delta

## MODIFIED Requirements

### Requirement: Backtest from an existing bot

The system SHALL let a user start a backtest from a bot they own and SHALL snapshot the bot, strategy, effective risk limits, protective exit rules, and period at creation. Laravel SHALL store the immutable snapshot, insert a queued run, and publish a `backtest.run` command. The snapshot SHALL remain readable after the source bot is deleted. The Go engine SHALL execute the queued run.

#### Scenario: Create a run from an owned bot

- **WHEN** a signed-in user starts a backtest from one of their bots
- **THEN** the system stores an immutable configuration snapshot and queues the run

#### Scenario: Another user's bot is rejected

- **WHEN** a user attempts to start a backtest from a bot they do not own
- **THEN** the request is denied and no run is created

#### Scenario: Later bot edits do not change a run

- **WHEN** a user edits the source bot after a run was created
- **THEN** the existing run continues to use its original snapshot

#### Scenario: Exit rules are snapshotted

- **WHEN** a run is created from a bot with protective exit rules
- **THEN** the run stores those rules and later edits to the bot do not change them

### Requirement: Deterministic strategy replay

The Go engine SHALL replay the snapshotted strategy through the same lifecycle used for live execution — initialization, tick, fill, cancel, and stop — in a side-effect-free simulation that does not write bot, order, fill, risk, or activity records. Laravel SHALL NOT replay strategies after cutover.

#### Scenario: Strategy lifecycle is honored

- **WHEN** the engine replays a candle sequence
- **THEN** it calls initialization once, ticks on each subsequent candle, and reports each fill and cancellation to the strategy

#### Scenario: First candle initializes and ticks

- **WHEN** the engine processes the first candle
- **THEN** it calls initialization and then a tick with the initialized state, and initial grid creation does not trigger an extra tick

#### Scenario: Multiple fills are processed sequentially

- **WHEN** more than one order fills on the same candle
- **THEN** each fill updates the portfolio and is reported to the strategy before the next fill is processed

#### Scenario: Simulation is isolated from live data

- **WHEN** a backtest runs
- **THEN** no live bot, order, fill, risk, or activity record is created or modified

### Requirement: Run lifecycle and failure reporting

The system SHALL expose pending, running, completed, and failed run states, SHALL record progress while running, SHALL prevent a duplicate command or job from executing the same run twice, and SHALL report a failed run with a concise non-secret error. The engine SHALL write status, progress, metrics, and backtest fills; Laravel SHALL display them.

#### Scenario: Successful run transitions

- **WHEN** a queued run begins and finishes normally
- **THEN** it transitions from pending to running to completed

#### Scenario: Duplicate job is ignored

- **WHEN** a second command or job attempts to execute an already-running or completed run
- **THEN** it does not process the run again

#### Scenario: Run fails

- **WHEN** candle retrieval or simulation raises an error
- **THEN** the run is marked failed with a concise message and the error is logged

### Requirement: Backtest dashboard

The system SHALL provide authenticated, owner-scoped pages to create, list, inspect, and delete backtest runs. Creation SHALL accept a bot, timeframe, and past date range, reject a range yielding more than 200,000 candles, and list and detail pages SHALL refresh while a run is active. The dashboard SHALL submit runs to the engine and read results from Postgres; it SHALL NOT execute the replay in Laravel.

#### Scenario: Create a run from the dashboard

- **WHEN** a user submits a bot, timeframe, and valid past range
- **THEN** the run is created and queued, and the page reports success

#### Scenario: Oversized range is rejected

- **WHEN** the selected range would yield more than 200,000 candles
- **THEN** creation fails with a field-level validation error

#### Scenario: Other users cannot view a run

- **WHEN** a user requests a run they do not own
- **THEN** the request is denied

#### Scenario: Active runs refresh

- **WHEN** a pending or running run is visible on the list or detail page
- **THEN** the page refreshes to show current status and progress

### Requirement: Parameter sweep creation

The system SHALL create a parameter sweep that runs one backtest for every combination of selected center prices, spacing values, and level counts against one bot, timeframe, and date range. Laravel SHALL create the sweep and queued runs and publish a `backtest.sweep` command; the Go engine SHALL execute the runs. A sweep SHALL create between 2 and the configured maximum runs and SHALL apply only those three parameters over each run's snapshot.

#### Scenario: Creates the cartesian product

- **WHEN** an owner submits two center prices, two spacing values, and two level counts for one bot
- **THEN** the system creates eight pending backtest runs grouped under one sweep

#### Scenario: Overrides only swept fields

- **WHEN** a sweep run is created from a bot whose strategy sets different values
- **THEN** the run snapshot uses the swept center price, spacing value, and level count while preserving every other strategy, risk, exit, and period field

#### Scenario: Rejects a set with one combination

- **WHEN** an owner submits values that produce only one run
- **THEN** the request is rejected without creating a sweep or any run
