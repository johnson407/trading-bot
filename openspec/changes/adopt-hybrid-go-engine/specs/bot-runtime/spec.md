# Spec Delta

## ADDED Requirements

### Requirement: Go trading engine process

A dedicated Go engine process SHALL evaluate running bots, subscribe to market data, place and synchronize orders, enforce runtime risk checks, reconcile on start, and execute backtests. Laravel SHALL NOT evaluate ticks, place or cancel trading orders, or replay backtests after the engine cutover. A single engine instance SHALL be the active consumer of engine commands in this version.

#### Scenario: Engine evaluates a running bot

- **WHEN** a bot is running and its tick interval has elapsed
- **THEN** the Go engine performs the evaluation and persists the result without a Laravel tick job

#### Scenario: Laravel does not place trading orders

- **WHEN** the engine cutover is complete
- **THEN** Laravel HTTP workers and Horizon do not place, cancel, or synchronize trading orders on the exchange

### Requirement: Engine heartbeat

The engine SHALL refresh a Redis heartbeat key at least every five seconds with a time-to-live of ten seconds, including a UTC timestamp and a count of running bots, and SHALL exclude secrets. Laravel health and the dashboard SHALL treat a missing or expired heartbeat as an engine outage and surface that state. The heartbeat SHALL NOT be implemented by calling an exchange.

#### Scenario: Heartbeat is visible while the engine is up

- **WHEN** the engine is running
- **THEN** Laravel can read a non-expired heartbeat and show the engine as available

#### Scenario: Expired heartbeat is an outage

- **WHEN** the heartbeat key is missing or expired
- **THEN** the dashboard or health view shows the engine as unavailable

### Requirement: Command and event streams

User-initiated start, pause, stop, order cancellation, kill-switch, and backtest requests SHALL be written to a versioned Redis Stream (`engine.commands`) with a unique idempotency key. The engine SHALL consume that stream, ignore a command whose idempotency key was already completed, and SHALL NOT execute a message whose schema version is higher than it supports. The engine SHALL publish versioned events on `engine.events`. Payloads SHALL NOT include credentials, `APP_KEY`, or signatures.

#### Scenario: Command is applied once

- **WHEN** the same command identifier is delivered twice
- **THEN** the engine performs the action at most once

#### Scenario: Unsupported schema version is rejected

- **WHEN** a command arrives with a schema version the engine does not support
- **THEN** the engine does not execute it and records a failure event without secrets

#### Scenario: Start is issued from the dashboard

- **WHEN** a user starts a bot
- **THEN** Laravel records the desired status in Postgres and publishes a `bot.start` command for the engine

## MODIFIED Requirements

### Requirement: Periodic tick execution

A running bot SHALL be evaluated by the Go engine at its stored tick interval, with a minimum interval of one second. New bots SHALL default to `trading.tick.interval_seconds` (`TRADING_TICK_INTERVAL`), which defaults to 60 seconds. The engine MAY wake more often as a due-check; a bot SHALL only be evaluated when its own interval has elapsed. A bot SHALL NOT have more than one tick in progress at a time.

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

Tick evaluation, order placement, order synchronization, and backtest execution SHALL run in the engine. Re-delivery of the same command or the same placement intent SHALL NOT create duplicate orders, duplicate fills, or duplicate inventory changes.

#### Scenario: Duplicate job delivery

- **WHEN** the command stream delivers the same start, placement, or cancel command twice
- **THEN** the second delivery makes no change and no duplicate order exists

#### Scenario: Queue backlog

- **WHEN** engine work for a bot is delayed
- **THEN** the bot continues from its last persisted state without skipping inventory or order updates

### Requirement: Restart recovery

On engine start the system SHALL resume bots that were running, reload their persisted strategy state, and reconcile local open orders with the exchange before processing new ticks. The Laravel scheduler SHALL NOT be responsible for pre-dispatch reconciliation after cutover.

#### Scenario: Resume a running bot

- **WHEN** the engine process starts and a bot was running before shutdown
- **THEN** the bot resumes from persisted state and continues processing ticks

#### Scenario: Reconcile open orders

- **WHEN** a resumed bot has locally open orders
- **THEN** the system confirms each order's status with the exchange and updates or removes orders that are filled or canceled

#### Scenario: Reconcile before scheduling

- **WHEN** the engine container starts
- **THEN** it reconciles running bots before it evaluates new ticks or places new orders

### Requirement: Scheduled maintenance

The engine SHALL run health checks, order synchronization, and balance synchronization for running bots. Health checks SHALL detect bots whose last successful tick is older than the effective stale threshold: the greater of the configured threshold (default 180 seconds) and the bot's interval plus the health-check interval. Balance sync SHALL refresh a live bot's balances unless the account is shared, with a shared-account skip recorded at most once per bot per UTC day.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the effective stale threshold
- **THEN** the engine pauses the bot and a health-check event is visible

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

The engine SHALL support at least ten concurrently running bots with one-second tick intervals on the documented local hardware profile without missing their schedule. Exchange sessions SHALL be reused within the engine process, and the engine SHALL NOT start a second tick for a bot while its interval guard is open.

#### Scenario: Ten concurrent bots

- **WHEN** ten bots are running with one-second intervals on the documented local environment
- **THEN** each bot completes its ticks without persistent schedule drift

#### Scenario: Duplicate dispatch suppressed

- **WHEN** a bot already has a tick in progress for its current interval
- **THEN** the engine does not start another tick for that bot
