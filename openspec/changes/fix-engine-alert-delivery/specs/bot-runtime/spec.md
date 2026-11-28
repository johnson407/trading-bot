# Spec Delta

## MODIFIED Requirements

### Requirement: Engine heartbeat

The engine SHALL refresh a Redis heartbeat at least every five seconds with a ten-second TTL, a UTC timestamp, and a running-bot count, excluding secrets. Laravel health and the dashboard SHALL treat a missing or expired heartbeat as an outage and show a warning only. The dead-man alert is specified by the `alerts` capability. A missing or expired heartbeat SHALL NOT automatically pause bots, cancel orders, or activate the kill-switch; user-triggered emergency cancellation remains available.

#### Scenario: Heartbeat is visible while the engine is up

- **WHEN** the engine is running
- **THEN** Laravel can read a non-expired heartbeat and show the engine as available

#### Scenario: Expired heartbeat is an outage

- **WHEN** the heartbeat key is missing or expired
- **THEN** the dashboard or health view shows the engine as unavailable

#### Scenario: Expired heartbeat does not pause or cancel

- **WHEN** the engine heartbeat is missing or expired and the user has not activated the kill-switch or stop
- **THEN** Laravel does not automatically pause bots, cancel orders, or activate the kill-switch, and only shows the engine as unavailable

#### Scenario: HTTP read paths do not create the alert

- **WHEN** a dashboard or health request reads a missing or expired heartbeat
- **THEN** it shows the outage and does not create an alert; the scheduled monitor creates the alert specified by the `alerts` capability

### Requirement: Activity and settings ownership

After cutover, `activity_logs` SHALL be insert-only except for nullable `alert_processed_at` and `alert_ingest_attempts` markers, which only the alert bridge and Laravel recorders may update. Existing rows SHALL be backfilled as processed. The engine SHALL NOT insert Laravel-listed types. Engine-owned `bots.settings` keys `mark_price`, `recent_prices`, and `daily_loss_baseline` SHALL be written only by the engine; Laravel SHALL merge later updates without overwriting them.

#### Scenario: Laravel bot update preserves engine settings

- **WHEN** Laravel updates a bot after the engine has stored mark or daily-loss keys in `settings`
- **THEN** those engine-owned keys are still present and unchanged

#### Scenario: Engine does not write Laravel activity types

- **WHEN** the engine records a runtime pause, fill, or cancel
- **THEN** it inserts only an engine-listed activity type and does not insert `bot.paused`, `bot.started`, or `bot.stopped`

#### Scenario: Alert processing preserves the activity event

- **WHEN** the alert bridge marks an activity row processed or increments its attempt count
- **THEN** the user, bot, type, severity, message, context, and creation time remain unchanged

#### Scenario: Historical activity rows are marked processed

- **WHEN** the marker migration runs against existing activity rows
- **THEN** every existing row has a processed marker and no historical alert is created
