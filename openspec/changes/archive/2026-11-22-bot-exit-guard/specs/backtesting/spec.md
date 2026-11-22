# Spec Delta

## ADDED Requirements

### Requirement: Replay protective exits

The system SHALL enforce the run's snapshotted protective exit rules at each candle close, using the same precedence as live execution: stop-loss, then take-profit, then range. A trigger SHALL cancel open simulated orders and end the run with a terminal protective-exit outcome. The run SHALL NOT resume.

#### Scenario: Stop-loss ends the replay

- **WHEN** a candle close is at or below the snapshotted stop-loss threshold while the simulated portfolio holds base inventory
- **THEN** open simulated orders are canceled and the run ends with the `stop_loss` outcome

#### Scenario: Take-profit ends the replay

- **WHEN** a candle close is at or above the snapshotted take-profit threshold while the simulated portfolio holds base inventory
- **THEN** open simulated orders are canceled and the run ends with the `take_profit` outcome

#### Scenario: Range exit ends the replay

- **WHEN** a candle close is at or below the snapshotted range low or at or above the snapshotted range high
- **THEN** open simulated orders are canceled and the run ends with the `range_exit` outcome

#### Scenario: Stop-loss takes precedence

- **WHEN** a candle close breaches both the stop-loss threshold and the range low
- **THEN** the run ends with the `stop_loss` outcome rather than the range outcome

#### Scenario: Rules remain inside their thresholds

- **WHEN** a candle close is inside every enabled threshold
- **THEN** the replay continues without an exit outcome

#### Scenario: Disabled rules are ignored

- **WHEN** the snapshot has no configured exit rules, or a rule is blank
- **THEN** that rule does not end the run

## MODIFIED Requirements

### Requirement: Backtest from an existing bot

The system SHALL let a user start a backtest from a bot they own and SHALL snapshot the bot, strategy, effective risk limits, protective exit rules, and period at creation. The snapshot SHALL be immutable, and a run SHALL remain readable after the source bot is deleted.

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

### Requirement: Run outcomes and metrics

The system SHALL record a run outcome of range end, risk pause, strategy stop, stop-loss, take-profit, or range exit, and SHALL report final equity, net, realized, and unrealized profit and loss, fees, fill counts, maximum drawdown, and an equity curve of at most 1,000 sampled points.

#### Scenario: Completed run reports metrics

- **WHEN** a run finishes
- **THEN** the stored result includes the outcome, profit and loss figures, fees, fill counts, and maximum drawdown

#### Scenario: Equity curve is bounded

- **WHEN** a run processes more candles than the sampling cap
- **THEN** the stored equity curve is downsampled to at most 1,000 points while preserving its first and last samples

#### Scenario: Protective exit outcome is recorded

- **WHEN** a replay ends because a protective exit rule triggers
- **THEN** the stored result reports the matching `stop_loss`, `take_profit`, or `range_exit` outcome
