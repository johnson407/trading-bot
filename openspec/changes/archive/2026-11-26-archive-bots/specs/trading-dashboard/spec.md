# Spec Delta

## MODIFIED Requirements

### Requirement: Bot list

The dashboard SHALL list only each user's active (non-archived) bots with the bot name, symbol, exchange, mode, running state, and current profit and loss. The list SHALL offer an archived view and SHALL reflect changes within five seconds.

#### Scenario: View the bot list

- **WHEN** a user opens the bot list
- **THEN** each active bot is shown with its symbol, exchange, mode, state, and current profit and loss

#### Scenario: Status freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the bot list reflects the change within five seconds

#### Scenario: Switch to the archived view

- **WHEN** a user opens the archived view from the bot list
- **THEN** the list shows that user's archived bots with a restore action instead of the active bots

### Requirement: Dashboard overview

The dashboard SHALL present an overview rather than a placeholder. It SHALL show the user's active bots by state, aggregate realized and unrealized profit and loss across active and archived bots, and recent activity, and SHALL refresh within five seconds. It SHALL also show whether the trading engine heartbeat is currently available, as a warning only when it is missing or expired.

#### Scenario: Open the dashboard

- **WHEN** a signed-in user opens the dashboard
- **THEN** active bot counts, aggregate profit and loss including archived bots, and recent activity are shown

#### Scenario: Archived balances stay in the total

- **WHEN** a user archives a bot that still has realized or unrealized profit and loss
- **THEN** the dashboard's aggregate profit and loss does not drop by the archived bot's amount, and the active bot counts no longer include it

#### Scenario: Archived activity is shown without a detail link

- **WHEN** recent activity references an archived bot
- **THEN** the activity shows the archived bot's name as plain text without a link to its detail page

#### Scenario: Dashboard freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the dashboard reflects the change within five seconds

#### Scenario: Engine availability is visible

- **WHEN** the engine heartbeat is missing or expired
- **THEN** the dashboard shows the engine as unavailable as a warning and does not automatically pause bots or cancel orders

## ADDED Requirements

### Requirement: Bot archiving controls

The bot detail page SHALL show an Archive action for an owned bot that is stopped, has no open orders, and, for a live bot, has a fresh engine heartbeat. It SHALL require explicit confirmation before archiving. The archived view SHALL be the only place that offers restore. Successful and rejected actions SHALL be reported on the page.

#### Scenario: Archive from the detail page

- **WHEN** the owner confirms archiving an eligible bot on its detail page
- **THEN** the bot is archived and the dashboard returns to the active bot list with a success message

#### Scenario: Archive is unavailable while ineligible

- **WHEN** a bot is running, paused, in error, has open orders, or is a live bot without a fresh heartbeat
- **THEN** its detail page does not offer the Archive action

#### Scenario: Archive availability follows bot state

- **WHEN** a bot becomes eligible or ineligible while its detail page is open
- **THEN** the Archive action appears or disappears within five seconds

#### Scenario: Show a rejected archive

- **WHEN** the owner submits an archive request for a bot that is not eligible
- **THEN** the page shows the rejection reason and the bot remains active

#### Scenario: Restore from the archived view

- **WHEN** the owner confirms restoring an archived bot
- **THEN** the bot leaves the archived view and appears in the active bot list in its stopped state

#### Scenario: Another owner sees no archive controls

- **WHEN** a user views a bot they do not own
- **THEN** the request is denied and no archive or restore control is shown
