# alerts Specification

## Purpose

Delivers owner-scoped alerts for trading incidents that require attention, and provides an in-app inbox where those alerts can be reviewed and marked as read.

## Requirements

### Requirement: Alert on operational incidents

The system SHALL create an alert when a covered incident occurs: a bot pauses from the daily-loss limit, consecutive exchange failures, a stale tick loop, a reconciliation conflict, the live-trading gate, or a protective exit; the user activates the kill-switch; or an order cancellation fails while pausing. It SHALL NOT create an alert for routine order, fill, balance, or expected inventory-deferral events.

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

### Requirement: Alert content and ownership

Each alert SHALL store the owner, the optional bot, the incident type, a severity, a human-readable title and message, the observed context, the originating risk-event or activity-log record when one exists, and its creation time. The alert SHALL belong to the bot owner and SHALL remain readable after the bot is archived or permanently deleted. An archived bot SHALL remain associated with its alerts so the alert can show the bot's name. Permanent deletion SHALL clear the bot association.

#### Scenario: Alert records the incident

- **WHEN** an alert is created for a bot incident
- **THEN** it stores the owner, bot, incident type, severity, title, message, observed context, source record when available, and creation time

#### Scenario: Alert survives bot archiving

- **WHEN** a bot is archived after creating an alert
- **THEN** the alert remains readable with its original message and still shows the archived bot's name

#### Scenario: Alert survives bot deletion

- **WHEN** a bot is permanently deleted after creating an alert
- **THEN** the alert remains readable with its original message and a missing bot association

### Requirement: Severity-based delivery channels

Every alert SHALL be delivered to the in-app inbox. A critical alert SHALL also be queued for email to the owner's verified address, identifying the bot when present, the incident, the observed values, and a link to the alert inbox. A warning alert SHALL be delivered in-app only.

#### Scenario: Critical alert sends email

- **WHEN** a critical alert such as a daily-loss pause or kill-switch activation is created
- **THEN** the alert appears in the inbox and an email is queued to the owner

#### Scenario: Warning alert stays in-app

- **WHEN** a warning alert such as a stale-tick pause is created
- **THEN** the alert appears in the inbox and no email is queued

### Requirement: Delivery isolation

The system SHALL dispatch external alert delivery only after the triggering database transaction commits. A delivery or mail-transport failure SHALL be logged and SHALL NOT roll back, fail, or delay the trade, pause, cancellation, or control action that produced the alert.

#### Scenario: Rolled-back action sends no email

- **WHEN** the transaction that produced an alert rolls back
- **THEN** neither the alert nor its email is delivered

#### Scenario: Mail failure does not undo the pause

- **WHEN** the mail transport fails while delivering a critical alert
- **THEN** the failure is logged and the bot's pause and the alert remain in place

#### Scenario: Queue outage does not block trading

- **WHEN** the queue is unavailable while an alert is created
- **THEN** the trading action completes and the alert remains available in the inbox

#### Scenario: Alert storage failure does not undo the pause

- **WHEN** the alert record cannot be stored while a bot is pausing
- **THEN** the failure is logged and the bot's pause and the source risk event or activity log remain in place

### Requirement: Alert deduplication

The system SHALL suppress a new alert when an alert with the same owner, bot, and incident type was already created within the configured deduplication window. The first alert in the window SHALL be delivered, and a new window SHALL allow a new alert.

#### Scenario: Repeated incident within the window

- **WHEN** the same incident type occurs twice for the same owner and bot within the deduplication window
- **THEN** only the first alert is created and delivered

#### Scenario: Incident after the window

- **WHEN** the same incident type occurs again after the deduplication window has passed
- **THEN** a new alert is created and delivered

#### Scenario: Different bot or incident type

- **WHEN** two alerts share an owner but differ in bot or incident type
- **THEN** both alerts are created

### Requirement: Alert inbox

The dashboard SHALL provide an authenticated, owner-scoped alert inbox listing alerts newest first with incident type, severity, bot, message, creation time, and read state. It SHALL show an unread count, allow filtering by bot, and let the user mark one or all visible alerts as read.

#### Scenario: View own alerts

- **WHEN** a signed-in user opens the alert inbox
- **THEN** only that user's alerts are shown, newest first, with their type, severity, bot, message, time, and read state

#### Scenario: Alert ownership is enforced

- **WHEN** a user requests an alert belonging to another user
- **THEN** the request is denied without revealing the alert

#### Scenario: Mark one alert as read

- **WHEN** a user marks an unread alert as read
- **THEN** the alert's read state changes and the unread count decreases

#### Scenario: Mark all visible alerts as read

- **WHEN** a user marks all visible alerts as read
- **THEN** every visible unread alert becomes read and the unread count reflects the change

#### Scenario: Filter by bot

- **WHEN** a user filters the inbox by a bot
- **THEN** only alerts for that bot are listed

#### Scenario: Unread count is visible

- **WHEN** a signed-in user has unread alerts
- **THEN** the primary navigation shows the unread count

### Requirement: Grouped alert inbox

The alerts inbox SHALL offer a grouped view that reduces repeated alert rows without changing alert storage, deduplication, delivery, or retention.

#### Scenario: Default grouped view
- **WHEN** an authenticated user opens the alerts inbox without selecting the all-alerts view
- **THEN** alerts from the last 30 days are grouped by alert type and bot, newest group first, with 20 groups per page

#### Scenario: Group summary
- **WHEN** the grouped view renders a group
- **THEN** the group shows its highest severity, alert type, bot link when the bot still exists, latest message, latest creation time, total alert count, and unread count

#### Scenario: Expand a group
- **WHEN** the user expands a group
- **THEN** the five most recent occurrences are shown and a View all action opens the ungrouped list with the same bot filter

#### Scenario: Mark a group read
- **WHEN** the user marks a group read
- **THEN** only unread alerts in that group at or before the group's displayed latest alert are marked read

#### Scenario: All alerts view
- **WHEN** the user selects All alerts
- **THEN** the existing ungrouped 50-per-page inbox is shown with the same bot filter and mark-read behavior

#### Scenario: Bot filter
- **WHEN** a bot filter is applied
- **THEN** both grouped and ungrouped views contain only alerts for that owned bot

#### Scenario: Ownership
- **WHEN** a user requests grouped alerts or marks a group read
- **THEN** only their own alerts are returned or changed

#### Scenario: Empty grouped view
- **WHEN** no alerts fall inside the 30-day window
- **THEN** the grouped view shows an empty state and the All alerts toggle remains available

### Requirement: Alert retention

The system SHALL delete alerts older than the configured retention period on a scheduled cadence. Retention SHALL default to 90 days and SHALL NOT delete the risk events or activity logs that sourced the alerts.

#### Scenario: Prune expired alerts

- **WHEN** the retention job runs and an alert is older than the retention period
- **THEN** the alert is deleted

#### Scenario: Preserve source records

- **WHEN** an expired alert is pruned
- **THEN** its source risk event or activity log remains stored

### Requirement: Alert content is non-secret

Alert messages, context, and email SHALL NOT contain exchange API keys, secrets, or stored credential values; the context SHALL be limited to the incident, bot, rule, observed values, configured limits, and a non-secret error message.

#### Scenario: Alert never includes credentials

- **WHEN** an alert is created for a live bot with stored exchange credentials
- **THEN** the inbox entry and any email contain no API key, secret, or credential value
