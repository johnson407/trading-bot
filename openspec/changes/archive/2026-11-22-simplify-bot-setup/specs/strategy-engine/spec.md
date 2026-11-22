# Spec Delta

## ADDED Requirements

### Requirement: Configuration validity at creation

A bot SHALL reject a strategy configuration that cannot produce a valid ladder before it is saved: geometric spacing of `1` or more, any generated level price that is zero or negative, and duplicate level prices. Strategy initialization SHALL continue to enforce the same validity at runtime.

#### Scenario: Reject invalid geometric spacing

- **WHEN** a bot is submitted with geometric spacing of `1` or more
- **THEN** validation fails and no bot is saved

#### Scenario: Reject a non-positive level price

- **WHEN** a bot is submitted with a center price and spacing that would generate a zero or negative level
- **THEN** validation fails and identifies the grid parameter at fault

#### Scenario: Runtime still validates

- **WHEN** a stored configuration cannot be initialized by its strategy at runtime
- **THEN** the bot places no orders and records an error for the user
