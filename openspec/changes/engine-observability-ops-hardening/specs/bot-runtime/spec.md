# Spec Delta

## MODIFIED Requirements

### Requirement: Observability

The system SHALL emit structured execution records for each bot tick, order operation, fill, cancellation, reconciliation, and error. Records SHALL include the bot identifier, operation, outcome, and correlation identifier, and SHALL exclude secrets.

#### Scenario: Trace a bot tick

- **WHEN** a bot tick completes
- **THEN** a structured record identifies the bot, the tick outcome, the duration, and any orders, fills, or grid changes produced

#### Scenario: Surface errors

- **WHEN** execution produces an error
- **THEN** the error is recorded with the bot identifier and is visible in the bot's activity log

#### Scenario: Records exclude secrets

- **WHEN** a tick, order, fill, cancellation, reconciliation, or error record is emitted
- **THEN** it contains no credential, API key, secret, signature, or internal token

## ADDED Requirements

### Requirement: Tick completion record

Every bot tick that starts SHALL emit exactly one completion record with its outcome, duration, and counts of orders placed and canceled, fills, and grid levels created, matched, or replaced. The outcome SHALL distinguish `completed`, `paused`, `failed`, and `panicked`.

#### Scenario: Successful tick is visible

- **WHEN** a tick completes successfully without placing orders or recording fills
- **THEN** a completion record is still emitted with zero counts and its duration, so an absent record means no tick ran

#### Scenario: Paused tick is distinct from success

- **WHEN** a tick pauses the bot for a stale, risk, exit, live-gate, or strategy reason
- **THEN** its completion record has outcome `paused` rather than `completed`

#### Scenario: Failed tick is distinct from success

- **WHEN** a tick fails before the consecutive-failure threshold, or the failure handler itself returns without an error
- **THEN** its completion record has outcome `failed` and includes the failure type

#### Scenario: Panicked tick is recorded

- **WHEN** a tick panics
- **THEN** its completion record has outcome `panicked` before the panic is re-raised for the scheduler's existing panic handling

#### Scenario: One completion record per tick

- **WHEN** a tick reaches any completion, pause, failure, or panic path
- **THEN** exactly one completion record is emitted for that tick

#### Scenario: Grid churn is measurable

- **WHEN** a tick replaces a grid
- **THEN** its record includes the number of levels created and canceled orders, so ladder replacement can be measured without querying the database

### Requirement: Tick record correlation

The completion record and every order, fill, cancellation, or reconciliation record produced by a tick SHALL share the tick's correlation identifier.

#### Scenario: Correlation joins a tick's records

- **WHEN** a tick places, cancels, fills, or reconciles an order
- **THEN** that record and the tick completion record share the tick's correlation identifier
