# Spec Delta

## Purpose

Defines the authenticated web interface through which a user creates bots, monitors their grids and performance, controls execution, and reviews activity and errors.

## ADDED Requirements

### Requirement: Authentication

Every dashboard page and action SHALL require an authenticated user. Unauthenticated requests SHALL be redirected to sign in, and authenticated users SHALL only see and act on their own bots and credentials.

#### Scenario: Reject an unauthenticated request

- **WHEN** an unauthenticated visitor requests a dashboard page
- **THEN** the visitor is redirected to sign in

#### Scenario: Enforce ownership

- **WHEN** a user requests a bot belonging to another user
- **THEN** the request is denied without revealing the bot's data

### Requirement: Bot creation

The dashboard SHALL provide a bot creation flow that captures the symbol, strategy, grid parameters, capital allocation, exchange, and trading mode. The flow SHALL validate inputs before saving and SHALL default the mode to paper.

#### Scenario: Create a paper bot

- **WHEN** a user submits valid bot settings without changing the mode
- **THEN** the bot is created in paper mode and appears in the bot list

#### Scenario: Reject invalid settings

- **WHEN** a user submits settings with an invalid symbol, missing strategy, or non-positive grid parameter
- **THEN** the form reports the invalid fields and no bot is created

### Requirement: Exchange credentials management

The dashboard SHALL let a user add exchange credentials for live trading. After saving, the secret SHALL be displayed only as a masked value and SHALL NOT be readable through the interface.

#### Scenario: Save credentials

- **WHEN** a user saves valid exchange credentials
- **THEN** the dashboard confirms the saved key in masked form and does not show the secret

#### Scenario: Replace credentials

- **WHEN** a user saves new credentials for an exchange that already has credentials
- **THEN** the previous credentials are replaced and no longer used

### Requirement: Bot list

The dashboard SHALL list each user's bots with the bot name, symbol, exchange, mode, running state, and current profit and loss. The list SHALL reflect changes within five seconds.

#### Scenario: View the bot list

- **WHEN** a user opens the bot list
- **THEN** each bot is shown with its symbol, exchange, mode, state, and current profit and loss

#### Scenario: Status freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the bot list reflects the change within five seconds

### Requirement: Bot detail and grid view

The bot detail page SHALL show the bot's grid levels, open orders, recent fills, inventory, and realized and unrealized profit and loss. The view SHALL update within five seconds of a change.

#### Scenario: Inspect a running bot

- **WHEN** a user opens the detail page of a running bot
- **THEN** the page shows its grid, open orders, recent fills, inventory, and profit and loss

#### Scenario: Reflect a fill

- **WHEN** a fill occurs
- **THEN** the detail page shows the fill and updated inventory within five seconds

### Requirement: Bot controls

The dashboard SHALL provide start, pause, and stop actions for each bot, plus a live-mode confirmation when the action could place real orders. Each action SHALL report success or failure to the user.

#### Scenario: Pause from the dashboard

- **WHEN** a user pauses a running bot
- **THEN** the dashboard confirms the action and the bot stops placing new orders

#### Scenario: Confirm a live action

- **WHEN** a user starts or resumes a live bot
- **THEN** the dashboard requires explicit confirmation before the action is sent

#### Scenario: Report a failed control action

- **WHEN** a control action fails
- **THEN** the dashboard shows the failure and the bot's previous state remains visible

### Requirement: Kill-switch access

The dashboard SHALL expose the account-wide kill-switch and SHALL require explicit confirmation before activating it.

#### Scenario: Activate from the dashboard

- **WHEN** a user confirms the kill-switch
- **THEN** all of the user's bots are paused and their open orders are canceled

### Requirement: Activity and errors

The dashboard SHALL show a per-bot activity log containing order, fill, cancellation, risk, reconciliation, and error events in reverse chronological order.

#### Scenario: Review recent activity

- **WHEN** a user opens a bot's activity log
- **THEN** recent events are shown with their time, type, and outcome

#### Scenario: See an execution error

- **WHEN** a bot records an execution or exchange error
- **THEN** the error appears in that bot's activity log and is flagged for attention
