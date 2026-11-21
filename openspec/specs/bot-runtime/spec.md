# bot-runtime Specification

## Purpose

Defines how bots are scheduled and executed continuously, how work survives restarts and duplicate deliveries, and how operators can observe bot health and errors.

## Requirements

### Requirement: Periodic tick execution

A running bot SHALL be evaluated at its stored tick interval, with a minimum interval of one second. New bots SHALL default to `trading.tick.interval_seconds` (`TRADING_TICK_INTERVAL`), which defaults to 60 seconds. The dispatcher MAY run every second as a due-check; a bot SHALL only be evaluated when its own interval has elapsed. A bot SHALL NOT have more than one tick in progress at a time.

#### Scenario: Tick at the configured interval

- **WHEN** a bot is running with an explicit one-second interval
- **THEN** the engine starts a new evaluation approximately every second

#### Scenario: Default 60-second interval

- **WHEN** a bot is created without an explicit tick interval
- **THEN** it stores the configured default of 60 seconds and is evaluated approximately every 60 seconds

#### Scenario: Overlapping ticks are prevented

- **WHEN** a tick is still running when the next interval elapses
- **THEN** the next tick waits and the bot never processes two ticks concurrently

#### Scenario: Tick-gated evaluation

- **WHEN** a 60-second bot's market price crosses an order limit between ticks
- **THEN** paper-fill detection and daily-loss evaluation wait for the next tick

### Requirement: Queued and idempotent work

Tick evaluation, order placement, and order synchronization SHALL run as queued work. Re-delivery of the same job SHALL NOT create duplicate orders, duplicate fills, or duplicate inventory changes.

#### Scenario: Duplicate job delivery

- **WHEN** the queue delivers the same order-placement job twice
- **THEN** the second delivery makes no change and no duplicate order exists

#### Scenario: Queue backlog

- **WHEN** queued work for a bot is delayed
- **THEN** the bot continues from its last persisted state without skipping inventory or order updates

### Requirement: Restart recovery

On worker or application restart the system SHALL resume bots that were running, reload their persisted state, and reconcile local open orders with the exchange before processing new ticks. The scheduler SHALL reconcile running bots before it begins dispatching ticks.

#### Scenario: Resume a running bot

- **WHEN** the application restarts and a bot was running before shutdown
- **THEN** the bot resumes from persisted state and continues processing ticks

#### Scenario: Reconcile open orders

- **WHEN** a resumed bot has locally open orders
- **THEN** the system confirms each order's status with the exchange and updates or removes orders that are filled or canceled

#### Scenario: Reconcile before scheduling

- **WHEN** the scheduler container starts
- **THEN** it runs reconciliation for running bots before `schedule:work` dispatches new ticks

### Requirement: Reconciliation conflicts

If reconciliation cannot match local state to the exchange, the system SHALL pause the affected bot, avoid placing new orders, and record a conflict describing the unmatched orders or inventory. When more than one live bot shares an exchange account, the whole-wallet balance comparison SHALL be skipped because the wallet is not attributable to one bot.

#### Scenario: Unmatched order

- **WHEN** reconciliation finds a locally open order the exchange does not recognize
- **THEN** the bot is paused and the discrepancy is recorded

#### Scenario: Unmatched inventory

- **WHEN** reconciled balances differ from recorded inventory beyond the configured tolerance on a single-bot account
- **THEN** the bot is paused and the difference is recorded

#### Scenario: Shared-account balances

- **WHEN** the reconciled bot shares its exchange account with another live bot
- **THEN** the balance comparison is skipped and no inventory conflict is recorded

### Requirement: Scheduled maintenance

The system SHALL run scheduled health checks and balance synchronization. A health check SHALL detect bots whose last successful tick is older than an effective stale threshold: the greater of the configured threshold and the bot's interval plus the health-check interval. The default threshold SHALL be 180 seconds. Balance sync SHALL refresh a live bot's balances unless its account is shared, skipping the whole-wallet comparison. Shared-account skips SHALL be recorded once per bot per UTC day.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the effective stale threshold
- **THEN** a health-check event is recorded and the stale state is visible

#### Scenario: Not stale within one interval

- **WHEN** a running bot with a 60-second interval last succeeded within its interval plus the health-check interval
- **THEN** the health check does not pause it

#### Scenario: Balance synchronization

- **WHEN** scheduled balance sync runs for a live bot on a single-bot account
- **THEN** its recorded quote and base balances are refreshed from the exchange

#### Scenario: Shared account is skipped

- **WHEN** scheduled balance sync runs for a live bot whose account is shared with another live bot
- **THEN** its inventory is unchanged and a `bot.balance_sync_skipped` activity records `reason: shared_account`

#### Scenario: Skip notice is not repeated

- **WHEN** the same shared-account bot is skipped again on the same UTC day
- **THEN** no additional skip activity is recorded

#### Scenario: Slow live tick is not stale

- **WHEN** a live tick takes longer than 15 seconds but less than the effective stale threshold
- **THEN** the bot is not paused by the health check

### Requirement: Execution capacity

The runtime SHALL support at least ten concurrently running bots with one-second tick intervals on the documented local hardware profile without missing their schedule. Exchange clients SHALL be reused within a worker, and dispatch SHALL not enqueue a second tick for a bot while its interval guard is open.

#### Scenario: Ten concurrent bots

- **WHEN** ten bots are running with one-second intervals on the documented local environment
- **THEN** each bot completes its ticks without persistent schedule drift

#### Scenario: Duplicate dispatch suppressed

- **WHEN** a bot already has a tick queued for its current interval
- **THEN** the dispatcher does not enqueue another tick for that bot

### Requirement: Observability

The system SHALL emit structured execution records for each bot tick, order operation, fill, cancellation, reconciliation, and error. Records SHALL include the bot identifier, the operation, the outcome, and a correlation identifier, and SHALL exclude secrets.

#### Scenario: Trace a bot tick

- **WHEN** a bot tick completes
- **THEN** a structured record identifies the bot, the tick outcome, and any orders or fills produced

#### Scenario: Surface errors

- **WHEN** execution produces an error
- **THEN** the error is recorded with the bot identifier and is visible in the bot's activity log
