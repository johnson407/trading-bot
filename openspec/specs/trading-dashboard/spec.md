# trading-dashboard Specification

## Purpose

Defines the authenticated web interface through which a user creates bots, monitors their grids and performance, controls execution, and reviews activity and errors.

## Requirements

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

### Requirement: Primary navigation

The authenticated layout SHALL link to the dashboard, the bot list, backtests, exchange credentials, and the kill switch from every authenticated page, on both desktop and responsive menus.

#### Scenario: Reach the bot list

- **WHEN** a signed-in user views any authenticated page
- **THEN** a navigation link to the bot list is available without typing a URL

#### Scenario: Reach credentials and the kill switch

- **WHEN** a signed-in user opens the navigation menu
- **THEN** links to exchange credentials and the kill switch are present

#### Scenario: Reach backtests

- **WHEN** a signed-in user opens the navigation menu
- **THEN** a link to the backtests section is present

### Requirement: Action feedback

Every state-changing dashboard action SHALL report its outcome to the user. Successful actions SHALL display the flash message produced by the controller, and failed actions SHALL display the relevant error without hiding the bot's previous state.

#### Scenario: Successful control action

- **WHEN** a user starts, pauses, or stops a bot successfully
- **THEN** a success message is displayed on the next page

#### Scenario: Failed live confirmation

- **WHEN** a live start is rejected because the confirmation is missing or wrong
- **THEN** the confirmation error is displayed on the bot page

#### Scenario: Failed control action

- **WHEN** a start, pause, or stop request fails
- **THEN** the failure is displayed and the bot's prior status remains visible

### Requirement: Dashboard overview

The dashboard SHALL present an overview rather than a placeholder. It SHALL show the user's bots by state, aggregate realized and unrealized profit and loss, and recent activity, and SHALL refresh within five seconds.

#### Scenario: Open the dashboard

- **WHEN** a signed-in user opens the dashboard
- **THEN** bot counts, aggregate profit and loss, and recent activity are shown

#### Scenario: Dashboard freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the dashboard reflects the change within five seconds

### Requirement: Bot list detail

The bot list SHALL show each bot's name, symbol, exchange, mode, state, open-order count, realized profit and loss, and unrealized profit and loss, and SHALL reflect changes within five seconds.

#### Scenario: View the bot list

- **WHEN** a user opens the bot list
- **THEN** each bot shows its exchange and unrealized profit and loss in addition to its existing columns

#### Scenario: Follow activity from the detail page

- **WHEN** a user opens a bot's detail page
- **THEN** a link to that bot's activity log is available

### Requirement: Protective exit configuration

The bot detail page SHALL show each optional protective exit rule and its current value. An owner SHALL be able to update the stop-loss percentage, take-profit percentage, and price-range bounds while the bot is idle, running, paused, or stopped. A blank field SHALL disable that rule, and a successful update SHALL be recorded in the bot's activity log.

#### Scenario: View configured rules

- **WHEN** a user opens the detail page of a bot with protective exit rules
- **THEN** the page shows the stop-loss percentage, take-profit percentage, and range low and high currently stored for that bot

#### Scenario: Update rules while running

- **WHEN** an owner saves valid exit rules for a running bot
- **THEN** the new rules are stored, the page reports success, and the bot applies them on its next tick

#### Scenario: Disable a rule

- **WHEN** an owner clears a previously configured exit field
- **THEN** that rule is disabled and the other rules remain unchanged

#### Scenario: Reject invalid rules

- **WHEN** an owner submits a stop-loss of one hundred percent or more, a non-positive value, or a range low at or above the range high
- **THEN** the form reports the invalid fields and the stored rules are unchanged

#### Scenario: Another user cannot change rules

- **WHEN** a user attempts to update exit rules for a bot they do not own
- **THEN** the request is denied and the bot's rules are unchanged

### Requirement: Bot editing

The dashboard SHALL allow editing an idle or stopped bot. Editable fields SHALL be limited to the bot name, capital allocation, fee rate, and strategy parameters. The symbol, exchange, trading mode, and strategy key SHALL be immutable after creation. Running or paused bots SHALL NOT be editable. Protective exit rules SHALL be configured separately from bot editing and SHALL remain editable in every bot state.

#### Scenario: Edit an idle bot

- **WHEN** a user submits valid changes for an idle bot
- **THEN** the bot and its strategy configuration are updated and the change is recorded in the activity log

#### Scenario: Reject editing a running bot

- **WHEN** a user attempts to edit a running or paused bot
- **THEN** the request is denied and the bot is left unchanged

#### Scenario: Identity fields cannot change

- **WHEN** an update request includes a symbol, exchange, mode, or strategy key
- **THEN** those values are ignored or rejected and the stored identity is unchanged

#### Scenario: Exit rules are not bot-edit fields

- **WHEN** a user edits an idle bot's name or strategy parameters
- **THEN** the bot's protective exit rules are unchanged
