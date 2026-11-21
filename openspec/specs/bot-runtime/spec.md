# bot-runtime Specification

## Purpose

Defines how bots are scheduled and executed continuously, how work survives restarts and duplicate deliveries, and how operators can observe bot health and errors.

## Requirements

### Requirement: Periodic tick execution

A running bot SHALL be evaluated at its configured tick interval, with a minimum interval of one second. A bot SHALL NOT have more than one tick in progress at a time.

#### Scenario: Tick at the configured interval

- **WHEN** a bot is running with a one-second interval
- **THEN** the engine starts a new evaluation approximately every second

#### Scenario: Overlapping ticks are prevented

- **WHEN** a tick is still running when the next interval elapses
- **THEN** the next tick waits and the bot never processes two ticks concurrently

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

The system SHALL run scheduled health checks and balance synchronization for running bots. Health checks SHALL detect bots whose last successful tick is older than the configured threshold. Balance synchronization SHALL refresh a live bot's recorded balances from the exchange unless the account is shared by multiple live bots.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the configured threshold
- **THEN** a health-check event is recorded and the stale state is visible

#### Scenario: Balance synchronization

- **WHEN** scheduled balance sync runs for a live bot on a single-bot account
- **THEN** its recorded quote and base balances are refreshed from the exchange

#### Scenario: Shared account is skipped

- **WHEN** scheduled balance sync runs for a live bot whose account is shared with another live bot
- **THEN** its inventory is unchanged and a `bot.balance_sync_skipped` activity records `reason: shared_account`

#### Scenario: Slow live tick is not stale

- **WHEN** a live tick takes longer than 15 seconds but less than the configured stale threshold
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
