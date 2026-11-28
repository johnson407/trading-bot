# Spec Delta

## ADDED Requirements

### Requirement: Daily-loss baseline failures fail closed

The engine SHALL NOT place orders on a tick when it cannot read account realized profit and loss, cannot read or persist a day-rollover baseline, or cannot persist a per-bot daily-loss baseline. A failed rollover SHALL NOT store a zero baseline and SHALL be retried on the next tick.

#### Scenario: Account loss read failure fails closed

- **WHEN** the account realized profit and loss query fails during daily-loss evaluation
- **THEN** the bot records the error and places no orders on that tick

#### Scenario: Rollover read failure does not store zero

- **WHEN** the account realized profit and loss cannot be read while rolling the UTC day
- **THEN** the stored account baseline is unchanged and the rollover is retried on the next tick

#### Scenario: Rollover persist failure retries

- **WHEN** the account baseline read succeeds but writing the new rollover day or baseline fails
- **THEN** the engine records the error, places no orders on that tick, and retries the rollover on the next tick

#### Scenario: Per-bot baseline persist failure fails closed

- **WHEN** the engine cannot persist a bot's new daily-loss baseline in its settings
- **THEN** the bot records the error and places no orders on that tick

### Requirement: Deduplicated invalid-order risk events

Repeated invalid orders for the same bot during one placement attempt SHALL be aggregated into one risk event that includes the number of skipped orders and a breakdown of reasons. Repeats inside the cooldown window SHALL NOT create additional events.

#### Scenario: Aggregate invalid orders in one tick

- **WHEN** several orders for one bot are skipped as invalid during the same placement attempt
- **THEN** one risk event records the total skipped count and the reasons

#### Scenario: Repeat inside the invalid-order cooldown is suppressed

- **WHEN** invalid orders recur on later ticks inside the cooldown window
- **THEN** no additional risk event is recorded

#### Scenario: Invalid-order cooldown expiry records a new event

- **WHEN** the cooldown window has elapsed and orders are still being skipped as invalid
- **THEN** a new aggregated risk event is recorded

#### Scenario: Failed event write allows a retry

- **WHEN** writing the aggregated invalid-order event fails
- **THEN** the cooldown reservation is released so a later attempt can record the event
