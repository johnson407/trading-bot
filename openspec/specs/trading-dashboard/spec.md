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

The dashboard SHALL offer a quick setup that captures name, symbol, exchange, capital allocation, center price, a setup preset, and trading mode, and an advanced mode that exposes every grid and strategy parameter. The flow SHALL validate inputs before saving, SHALL default the mode to paper, SHALL show a preview of the resulting ladder with advisory warnings, and SHALL NOT block creation when only a risk limit is exceeded.

#### Scenario: Create a paper bot

- **WHEN** a user submits valid bot settings without changing the mode
- **THEN** the bot is created in paper mode and appears in the bot list

#### Scenario: Reject invalid settings

- **WHEN** a user submits settings with an invalid symbol, missing strategy, or non-positive grid parameter
- **THEN** the form reports the invalid fields and no bot is created

#### Scenario: Create from quick setup

- **WHEN** a user selects a preset and enters a name, symbol, exchange, capital allocation, and center price, then submits
- **THEN** the derived grid parameters are submitted with the bot and the bot appears in the bot list

#### Scenario: Preview the ladder

- **WHEN** a user changes the symbol, capital allocation, center price, preset, or an advanced grid value
- **THEN** the form shows the evaluated levels, per-order size, and totals for the resulting setup

#### Scenario: Warnings do not block creation

- **WHEN** the preview reports only advisory warnings
- **THEN** the submit action remains available and the bot is created

#### Scenario: Advanced mode preserves derived values

- **WHEN** a user switches from quick setup to advanced
- **THEN** every value derived from the preset is present in the advanced fields rather than reset to placeholders

#### Scenario: Edit uses the same evaluation

- **WHEN** a user edits the grid parameters of an idle bot
- **THEN** the form shows the same plan evaluation and advisory warnings as creation before saving

### Requirement: Account settings hub

The dashboard SHALL provide a Settings hub that groups account configuration. The hub SHALL link to exchange credentials and risk limits, and every settings page SHALL require an authenticated user.

#### Scenario: Open the settings hub

- **WHEN** a signed-in user opens the Settings hub
- **THEN** links to exchange credentials and risk limits are present

#### Scenario: Open exchange credentials from settings

- **WHEN** a signed-in user selects exchange credentials in the Settings hub
- **THEN** the exchange credential list is shown

#### Scenario: Open risk limits from settings

- **WHEN** a signed-in user selects risk limits in the Settings hub
- **THEN** the account risk-limit form is shown

#### Scenario: Reject an unauthenticated settings request

- **WHEN** an unauthenticated visitor requests a settings page
- **THEN** the visitor is redirected to sign in

### Requirement: Exchange credentials management

The dashboard SHALL let a user add exchange credentials for live trading from the Settings area. After saving, the secret SHALL be displayed only as a masked value and SHALL NOT be readable through the interface.

#### Scenario: Reach credentials from settings

- **WHEN** a user opens the Settings hub
- **THEN** a link to exchange credentials is available without typing a URL

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

The dashboard SHALL allow a bot's owner to start, pause, and stop the bot, and SHALL show the outcome of those actions. Start, pause, and stop SHALL record `desired_status` in Postgres and publish the corresponding engine command. The dashboard SHALL continue to refresh by polling at most every five seconds and SHALL NOT require a browser WebSocket. Starting or resuming a live bot SHALL require explicit confirmation before the command is sent.

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

The dashboard SHALL expose the account-wide kill-switch from the bot list as a danger-styled control beside Create bot, and SHALL require explicit confirmation before activating it. Activation SHALL write `desired_status` paused and publish a `kill_switch` command. If the engine heartbeat is missing or expired or publish fails, Laravel SHALL also cancel open live orders in that same action.

#### Scenario: Locate the kill switch

- **WHEN** a user opens the bot list
- **THEN** a danger-styled Kill switch control is shown next to Create bot

#### Scenario: Activate from the dashboard

- **WHEN** a user confirms the kill-switch after opening it from the bot list
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

The authenticated layout SHALL link to the dashboard, alerts, the bot list, backtests, and Settings from every authenticated page, on both desktop and responsive menus. The Settings hub SHALL link to exchange credentials and risk limits. The kill switch SHALL NOT appear in primary navigation.

#### Scenario: Reach the bot list

- **WHEN** a signed-in user views any authenticated page
- **THEN** a navigation link to the bot list is available without typing a URL

#### Scenario: Reach settings

- **WHEN** a signed-in user opens the navigation menu
- **THEN** a link to Settings is present

#### Scenario: Reach credentials and the kill switch

- **WHEN** a signed-in user opens the navigation menu and the Settings hub
- **THEN** credentials are reachable through Settings, and the kill switch is reachable from the bot list

#### Scenario: Keep the kill switch out of primary navigation

- **WHEN** a signed-in user opens the desktop or responsive navigation menu
- **THEN** the kill switch is not listed among the primary navigation links

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

The dashboard SHALL present an overview rather than a placeholder. It SHALL show the user's bots by state, aggregate realized and unrealized profit and loss, and recent activity, and SHALL refresh within five seconds. It SHALL also show whether the trading engine heartbeat is currently available, as a warning only when it is missing or expired.

#### Scenario: Open the dashboard

- **WHEN** a signed-in user opens the dashboard
- **THEN** bot counts, aggregate profit and loss, and recent activity are shown

#### Scenario: Dashboard freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the dashboard reflects the change within five seconds

#### Scenario: Engine availability is visible

- **WHEN** the engine heartbeat is missing or expired
- **THEN** the dashboard shows the engine as unavailable as a warning and does not automatically pause bots or cancel orders

### Requirement: Equity history

The dashboard SHALL show an account equity-history section with a chart, the selected range, and period return, peak equity, and maximum drawdown. It SHALL offer 7-, 30-, and 90-day ranges, defaulting to 30 days, and SHALL refresh without a full page reload at most once per minute.

#### Scenario: Open the default range

- **WHEN** a signed-in user opens the dashboard
- **THEN** the equity chart and metrics cover the last 30 days

#### Scenario: Change the range

- **WHEN** a user selects the 7-day or 90-day range
- **THEN** the chart and metrics update to that period

#### Scenario: Show an empty state

- **WHEN** no snapshots exist for the selected range
- **THEN** the section explains that equity history is still being collected

#### Scenario: Show a single point

- **WHEN** the selected range contains one snapshot
- **THEN** the point is shown without performance metrics

#### Scenario: Refresh without a reload

- **WHEN** a new snapshot is captured
- **THEN** the section reflects it without a full page reload within one minute

#### Scenario: Keep history private

- **WHEN** a user opens the dashboard
- **THEN** the equity history contains only that user's snapshots

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

### Requirement: Parameter sweep pages
Authenticated owners SHALL be able to create, list, and inspect parameter sweeps. The create page SHALL select one grid bot, timeframe, date range, and multiple center prices, spacing values, and level counts, and SHALL show the resulting combination count. The detail page SHALL list each run's swept values and outcome, poll while runs are active, and link to individual runs and the comparison view. Sweeps and runs belonging to another owner SHALL NOT be visible.

#### Scenario: Creates a sweep from the dashboard
- **WHEN** an owner opens the sweep create page, selects an owned grid bot, and submits valid multi-value parameters
- **THEN** the page redirects to the new sweep detail page

#### Scenario: Shows combination count before submission
- **WHEN** an owner changes the number of center prices, spacing values, or level counts
- **THEN** the create page displays the resulting run count and warns when it exceeds the configured maximum

#### Scenario: Keeps another owner out
- **WHEN** a user requests a sweep or sweep run owned by someone else
- **THEN** the response is not found

### Requirement: Live mode status is explicit

The dashboard and bot setup SHALL present live mode as an available, gated mode rather than as unimplemented. When a bot or credential is configured for testnet, the UI SHALL state that orders target the exchange testnet. When `TRADING_LIVE_ENABLED` is false, the UI SHALL make clear that live orders are blocked by the gate and that the bot is not silently running as paper.

#### Scenario: Testnet is labeled

- **WHEN** a live bot is configured to use testnet
- **THEN** the dashboard or bot view states that the bot targets the exchange testnet

#### Scenario: Closed gate is visible

- **WHEN** `TRADING_LIVE_ENABLED` is false and a user views a live bot
- **THEN** the UI indicates that live execution is gated off and does not imply that orders are being sent
