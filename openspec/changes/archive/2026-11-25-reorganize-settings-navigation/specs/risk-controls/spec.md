# Spec Delta

## MODIFIED Requirements

### Requirement: Risk limit configuration

Account-scope risk limits SHALL apply to every bot that does not have an explicit per-bot override. A user SHALL be able to view and update the account limits from the Settings area. Legacy bot-scope rows created automatically for every bot SHALL be removed.

#### Scenario: Apply account limits to a bot without an override

- **WHEN** a bot has no explicit override and an order would exceed the account position or open-order limit
- **THEN** the order is blocked by the account limit

#### Scenario: Update account limits

- **WHEN** a user saves valid account limits from the Settings area
- **THEN** the new limits are stored and applied to every bot without an override

#### Scenario: Reject invalid limits

- **WHEN** a user submits a non-positive position or daily-loss limit, or an out-of-range open-order limit
- **THEN** the form reports validation errors and the stored limits are unchanged
