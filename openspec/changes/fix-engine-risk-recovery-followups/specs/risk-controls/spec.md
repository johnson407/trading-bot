# Spec Delta

## ADDED Requirements

### Requirement: Daily-loss baseline failures fail closed

The engine SHALL NOT place orders on a tick when it cannot read account realized profit and loss, when reading or writing an existing account-scope rollover baseline fails, or when it cannot persist a per-bot daily-loss baseline. A failed rollover SHALL NOT store a zero baseline and SHALL be retried on the next tick.

#### Scenario: Account loss read failure fails closed

- **WHEN** the account realized profit and loss query fails during daily-loss evaluation
- **THEN** the bot records the error and places no orders on that tick

#### Scenario: Rollover read failure does not store zero

- **WHEN** the account realized profit and loss cannot be read while rolling the UTC day
- **THEN** the stored account baseline is unchanged and the rollover is retried on the next tick

#### Scenario: Rollover persist failure retries

- **WHEN** the account baseline read succeeds but writing the new rollover day or baseline fails
- **THEN** the engine records the error, places no orders on that tick, and retries the rollover on the next tick

#### Scenario: A rollover already completed by another tick is not repeated

- **WHEN** another tick has already rolled the account baseline to the current UTC day
- **THEN** the zero-row rollover update is treated as success, the stored baseline is kept, and this tick still evaluates normally

#### Scenario: No account-scope row keeps the default in-memory baseline

- **WHEN** the user has no account-scope risk-limit row
- **THEN** the engine uses the built-in default account limit and the current realized total as an in-memory baseline, without persisting an account baseline

#### Scenario: Per-bot baseline persist failure fails closed

- **WHEN** the engine cannot persist a bot's new daily-loss baseline in its settings
- **THEN** the bot records the error and places no orders on that tick

### Requirement: Deduplicated invalid-order risk events

Repeated invalid orders for the same bot during one placement attempt SHALL be recorded as at most one `risk.invalid_order` event with `orders_skipped` and a `reasons` breakdown. Repeats inside the cooldown window SHALL NOT create additional events while the bot remains running.

#### Scenario: Aggregate invalid orders in one tick

- **WHEN** several orders for one bot are skipped as invalid during the same placement attempt
- **THEN** one risk event records the total skipped count and the reasons

#### Scenario: One event carries the per-reason breakdown

- **WHEN** several skips in one attempt have different invalid-order reasons
- **THEN** the single `risk.invalid_order` event carries the total and one count per reason, with no separate event per reason

#### Scenario: Repeat inside the invalid-order cooldown is suppressed

- **WHEN** invalid orders recur on later ticks inside the cooldown window
- **THEN** no additional risk event is recorded

#### Scenario: Invalid-order cooldown expiry records a new event

- **WHEN** the cooldown window has elapsed and orders are still being skipped as invalid
- **THEN** a new aggregated risk event is recorded

#### Scenario: Failed event write allows a retry

- **WHEN** writing the aggregated invalid-order event fails
- **THEN** the cooldown reservation is released so a later attempt can record the event
