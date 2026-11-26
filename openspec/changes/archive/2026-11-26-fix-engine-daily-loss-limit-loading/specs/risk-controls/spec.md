# Spec Delta

## ADDED Requirements

### Requirement: Daily loss limit resolution

The engine SHALL resolve the effective daily-loss limit from an explicit bot-scope override first, then the account-scope row, then the built-in default, evaluating each column independently. It SHALL NOT place orders when the daily-loss limit or the account daily baseline cannot be read; it SHALL record a clear error for the bot instead.

#### Scenario: Bot override wins

- **WHEN** a bot has an explicit bot-scope daily-loss limit and the account also has a limit
- **THEN** the engine enforces the bot-scope limit

#### Scenario: NULL bot override falls back to the account limit

- **WHEN** a bot-scope row exists but its daily-loss limit is NULL and the account row has a limit
- **THEN** the engine enforces the account limit instead of the built-in default

#### Scenario: No configured limits fall back to the default

- **WHEN** neither a bot-scope nor an account-scope daily-loss limit is stored
- **THEN** the engine enforces the built-in default daily-loss limit

#### Scenario: Unreadable limit data fails closed

- **WHEN** the daily-loss limit or account daily baseline cannot be read from the database
- **THEN** the bot records an error and does not place orders on that tick
