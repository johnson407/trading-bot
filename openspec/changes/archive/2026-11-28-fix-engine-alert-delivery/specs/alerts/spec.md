# Spec Delta

## ADDED Requirements

### Requirement: Engine-originated incident ingestion

The system SHALL ingest unprocessed safety events written directly by the trading engine into `risk_events` and `activity_logs`, create alerts through the same recorder used for Laravel-side events, and mark each source row processed only after the alert attempt. Ingestion SHALL be idempotent, use one transaction per source row, and SHALL NOT replay rows that predate the bridge. Laravel-side recorders SHALL mark their own source rows processed after their synchronous alert attempt.

#### Scenario: Engine risk event creates an alert

- **WHEN** the scanner finds an unprocessed engine-written risk event whose type is in the alert catalog
- **THEN** an alert is created for the bot's owner with the catalog severity and title, the event's message and context, and the source record

#### Scenario: Engine activity creates an alert

- **WHEN** the scanner finds an unprocessed engine-written activity log whose type is in the alert catalog
- **THEN** an alert is created with the catalog severity and title, the activity's message and context, and the source record

#### Scenario: Processed source is skipped

- **WHEN** the scanner encounters a source row already marked processed
- **THEN** it creates no alert and leaves the row unchanged

#### Scenario: Non-alertable event is marked processed

- **WHEN** the scanner encounters an engine event whose type is not in the alert catalog
- **THEN** it creates no alert and marks the row processed so it is not rescanned

#### Scenario: Historical rows are not replayed

- **WHEN** the bridge migration runs against existing risk events and activity logs
- **THEN** those rows are marked processed and no historical alert is created

#### Scenario: Laravel-recorded row is not re-alerted

- **WHEN** a Laravel recorder has already attempted an alert for its source row and marked that row processed
- **THEN** the engine-event scanner does not create a second alert for that row

#### Scenario: Duplicate source cannot create a second alert

- **WHEN** ingestion retries a source row after a transaction or deduplication-window boundary
- **THEN** the unique alert source constraint prevents a second alert, the recorder reports the source as deduplicated, and the row is marked processed rather than retried as a failure

#### Scenario: Failed ingestion retries with backoff

- **WHEN** alert recording for a source row fails
- **THEN** that row's transaction rolls back, its attempt count increments, a later retry is scheduled with exponential backoff, and the row is never marked processed

#### Scenario: Repeated ingestion failure becomes visible

- **WHEN** a source row has failed alert recording six times
- **THEN** the scanner creates one deduplicated critical `alerts.ingestion_failed` alert for that source row and keeps retrying

#### Scenario: Engine kill-switch event does not duplicate the account alert

- **WHEN** the engine records one `risk.kill_switch_activated` event per paused bot
- **THEN** the scanner marks those engine events processed without creating per-bot alerts, leaving the Laravel account-level `kill_switch.activated` alert as the single kill-switch notification

### Requirement: Heartbeat outage alert

The system SHALL create one deduplicated critical `engine.heartbeat_missing` alert for each account with a non-archived live bot whose status is running or paused when the engine heartbeat is missing or expired. The alert SHALL NOT automatically pause bots, cancel orders, or activate the kill switch. Repeated checks inside the deduplication window SHALL create no additional alert, and a later outage after recovery SHALL create a new alert.

#### Scenario: Missing heartbeat creates an account alert

- **WHEN** the heartbeat key is missing or expired and an account has a non-archived live bot whose status is running or paused
- **THEN** one critical account-level alert with type `engine.heartbeat_missing` is created for that account's owner

#### Scenario: Heartbeat alert does not change trading state

- **WHEN** the heartbeat monitor creates an outage alert
- **THEN** no bot status, desired status, open order, or kill-switch state is changed

#### Scenario: Sustained outage is deduplicated

- **WHEN** the heartbeat remains missing or expired across repeated monitor runs inside the deduplication window
- **THEN** only the first run creates an alert and later runs inside that window create none

#### Scenario: A later outage creates a new alert

- **WHEN** the heartbeat recovers and later becomes missing or expired after the deduplication window
- **THEN** a new critical outage alert is created

#### Scenario: Accounts without live exposure get no heartbeat alert

- **WHEN** the heartbeat is missing or expired and an account has no non-archived live bot that is running or paused
- **THEN** no heartbeat alert is created for that account

## MODIFIED Requirements

### Requirement: Alert on operational incidents

The system SHALL create an alert when a covered incident occurs: a bot pauses from the daily-loss limit, consecutive exchange failures, a stale tick loop, a reconciliation conflict, the live-trading gate, or a protective exit; the engine refuses a live start or rejects a live order; the user activates the kill-switch; or an order cancellation fails while pausing. It SHALL NOT create an alert for routine order, fill, balance, or expected inventory-deferral events.

#### Scenario: Daily-loss pause creates an alert

- **WHEN** a bot pauses because its per-bot or account-wide daily loss limit is reached
- **THEN** an alert is created for the bot's owner with the daily-loss incident type

#### Scenario: Consecutive failure pause creates an alert

- **WHEN** a bot pauses after reaching the configured consecutive exchange failure threshold
- **THEN** an alert is created for the bot's owner with the exchange-failure incident type

#### Scenario: Stale tick pause creates an alert

- **WHEN** the health check pauses a bot because its tick loop is stale
- **THEN** an alert is created for the bot's owner with the stale-bot incident type

#### Scenario: Reconciliation conflict creates an alert

- **WHEN** reconciliation pauses a bot after detecting a conflict with the exchange
- **THEN** a critical alert is created for the bot's owner with the reconciliation incident type

#### Scenario: Live-trading gate creates an alert

- **WHEN** a live bot pauses because live trading is disabled
- **THEN** a critical alert is created for the bot's owner with the live-gate incident type

#### Scenario: Refused live start creates an alert

- **WHEN** the engine refuses to start a live bot because the live gate, credential, balance, or exchange check fails
- **THEN** an alert is created for the bot's owner with the live-start-refused incident type

#### Scenario: Rejected live order creates an alert

- **WHEN** the exchange rejects a live order or the engine refuses to submit it
- **THEN** an alert is created for the bot's owner with the live-order-rejected incident type

#### Scenario: Kill-switch activation creates an account alert

- **WHEN** a user activates the account-wide kill-switch
- **THEN** an account-level alert is created for that user without a bot association

#### Scenario: Protective exit creates an alert

- **WHEN** a bot pauses because a stop-loss, take-profit, or range exit triggers
- **THEN** an alert is created for the bot's owner with the matching protective-exit incident type

#### Scenario: Cancellation failure while pausing creates an alert

- **WHEN** an open order cannot be canceled while a bot is pausing for a risk breach, including a protective exit
- **THEN** an alert is created for the bot's owner with the cancellation-failure incident type

#### Scenario: Expected inventory deferral does not alert

- **WHEN** a sell is deferred because the bot does not yet hold enough base inventory
- **THEN** no alert is created

#### Scenario: Routine trading activity does not alert

- **WHEN** an order is placed, filled, or canceled normally, or a balance sync succeeds
- **THEN** no alert is created
