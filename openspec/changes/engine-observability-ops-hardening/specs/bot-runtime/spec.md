# Spec Delta

## MODIFIED Requirements

### Requirement: Observability

The system SHALL emit structured execution records for each bot tick, order operation, fill, cancellation, reconciliation, and error. Every completed bot tick SHALL emit one completion record with the bot identifier, outcome, duration, counts of orders placed and canceled, fills, and grid levels created, matched, or replaced. Records SHALL include the bot identifier, the operation, the outcome, and a correlation identifier, and SHALL exclude secrets.

#### Scenario: Trace a bot tick

- **WHEN** a bot tick completes
- **THEN** a structured record identifies the bot, the tick outcome, the duration, and any orders, fills, or grid changes produced

#### Scenario: Successful tick is visible

- **WHEN** a tick completes successfully without placing orders or recording fills
- **THEN** a completion record is still emitted with zero counts and its duration, so an absent record means no tick ran

#### Scenario: Surface errors

- **WHEN** execution produces an error
- **THEN** the error is recorded with the bot identifier and is visible in the bot's activity log

#### Scenario: Grid churn is measurable

- **WHEN** a tick replaces a grid
- **THEN** its record includes the number of levels created and canceled orders, so ladder replacement can be measured without querying the database

#### Scenario: Records exclude secrets

- **WHEN** a tick, order, fill, cancellation, reconciliation, or error record is emitted
- **THEN** it contains no credential, API key, secret, signature, or internal token
