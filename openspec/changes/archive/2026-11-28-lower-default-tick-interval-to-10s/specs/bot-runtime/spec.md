# Spec Delta

## ADDED Requirements

### Requirement: Tick scheduling and cadence

A running bot SHALL be evaluated by the Go engine at its stored tick interval, with a minimum interval of one second. New bots SHALL default to `trading.tick.interval_seconds` (`TRADING_TICK_INTERVAL`), which defaults to 10 seconds. The engine MAY wake more often as a due-check; a bot SHALL only be evaluated when its own interval has elapsed. A bot SHALL NOT have more than one tick in progress at a time.

#### Scenario: Tick at the configured interval

- **WHEN** a bot is running with an explicit one-second interval
- **THEN** the engine starts a new evaluation approximately every second

#### Scenario: Default 10-second interval

- **WHEN** a bot is created without an explicit tick interval
- **THEN** it stores the configured default of 10 seconds and is evaluated approximately every 10 seconds

#### Scenario: Overlapping ticks are prevented

- **WHEN** a tick is still running when the next interval elapses
- **THEN** the next tick waits and the bot never processes two ticks concurrently

#### Scenario: Tick-gated evaluation

- **WHEN** a 10-second bot's market price crosses an order limit between ticks
- **THEN** paper-fill detection and daily-loss evaluation wait for the next tick

### Requirement: Stored tick interval updates apply without restart

The engine SHALL re-read each running bot's stored tick interval on every due-check. Changing a bot's stored interval SHALL apply from the next due-check without an engine restart or a configuration command, and the interval change itself SHALL NOT request a reconcile, cancel open orders, or rebuild the ladder; the separate stale check in "Lowering an interval respects the stale check" may still pause an overdue bot. A tick already in progress SHALL finish under the interval it started with.

#### Scenario: Lowering a running bot's interval

- **WHEN** a running bot's stored interval changes from 60 seconds to 10 seconds and its last successful tick is within the new threshold
- **THEN** the engine schedules its next evaluation using 10 seconds once the in-flight tick, if any, completes

#### Scenario: No order rebuild for an interval change

- **WHEN** only the stored tick interval changes
- **THEN** the interval change does not itself cancel open orders, rebuild the ladder, or request a reconcile; an already-pending reconcile or reset still runs, and the separate stale check may still pause an overdue bot

### Requirement: Lowering an interval respects the stale check

The stale check SHALL continue to use the effective threshold `max(180s, interval + 30s)` against the stored interval. Lowering an interval SHALL NOT be treated as a reconcile, but a bot whose last successful tick is already older than the new threshold SHALL be paused as a stale tick.

#### Scenario: Lowering a long interval can trip the stale check

- **WHEN** a running paper bot with a 3600-second interval and a last successful tick 300 seconds old is edited to 10 seconds
- **THEN** the engine pauses it as a stale tick and cancels its open orders, and the pause is reported as a stale-tick pause rather than a reconcile

## REMOVED Requirements

### Requirement: Periodic tick execution

**Reason**: Superseded by "Tick scheduling and cadence", which carries the same scheduling guarantees with the new 10-second default and removes the misleading 60-second scenario label.

**Migration**: No runtime action is needed beyond the `bots.tick_interval_seconds` data migration in this change. Running bots re-read their stored interval on the next due-check.
