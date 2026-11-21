# Spec Delta

## MODIFIED Requirements

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
