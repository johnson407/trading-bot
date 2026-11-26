# Spec Delta

## ADDED Requirements

### Requirement: Deduplicated blocked-order risk events

Repeated order blocks for the same bot, reason, and placement attempt SHALL be aggregated into one risk event that includes the number of skipped orders. Repeats inside a cooldown window SHALL NOT create additional events.

#### Scenario: Aggregate blocked orders in one tick

- **WHEN** several orders for one bot are blocked by the position limit during the same placement attempt
- **THEN** one risk event records the block and the number of skipped orders

#### Scenario: Repeat inside the cooldown is suppressed

- **WHEN** the same position-limit block recurs on later ticks inside the cooldown window
- **THEN** no additional risk event is recorded

#### Scenario: Cooldown expiry records a new event

- **WHEN** the cooldown window has elapsed and the position-limit block is still present
- **THEN** a new aggregated risk event is recorded
