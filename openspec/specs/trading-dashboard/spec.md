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

### Requirement: Source-backed creation UI

When the bot creation form is opened from a completed backtest run, it SHALL use advanced paper-only source mode, show a source banner, render source-derived fields as read-only, keep the name editable, hide live and current-price controls, and submit the source run ID.

#### Scenario: Source mode opens in advanced paper-only layout
- **WHEN** the form is opened with a source backtest run
- **THEN** it starts in advanced mode, hides quick setup and live controls, and shows a paper-only badge

#### Scenario: Source banner explains provenance and idle creation
- **WHEN** the source-backed form renders
- **THEN** the banner shows the source run and states that risk limits are current and the bot is created idle

#### Scenario: Name is editable while source settings are read-only
- **WHEN** the user edits the source-backed form
- **THEN** the name can be changed and source-derived settings cannot

#### Scenario: Live and current-price controls are hidden
- **WHEN** the source-backed form renders
- **THEN** no live-mode selector, live confirmation input, or "use current price" action is shown

#### Scenario: No source keeps the existing form
- **WHEN** the form is opened without a source backtest run
- **THEN** the existing quick/advanced, paper/live, and current-price behavior is unchanged

#### Scenario: Source defaults are applied before watchers
- **WHEN** the source-backed form initializes
- **THEN** source values are applied before default-field watchers can overwrite them

### Requirement: Source-backed preview parity

The source-backed form's preview SHALL use the same server-derived settings as submission.

#### Scenario: Preview uses the source derivation
- **WHEN** the source-backed form requests a preview
- **THEN** the request includes the source run ID and the preview uses the same snapshot-derived settings as store

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

### Requirement: Bot detail and grid view

The bot detail page SHALL show the bot's grid levels, open orders, recent fills, inventory, and realized and unrealized profit and loss. For a grid level with an open order whose side differs from the level's plan side, the page SHALL show both the plan side and the actual order side. The view SHALL update within five seconds of a change.

#### Scenario: Inspect a running bot

- **WHEN** a user opens the detail page of a running bot
- **THEN** the page shows its grid, open orders, recent fills, inventory, and profit and loss

#### Scenario: Opposite order at a plan level

- **WHEN** a grid level's plan side is buy but it holds an open sell order
- **THEN** the page shows the level's plan side and the order's actual sell side

#### Scenario: Reflect a fill

- **WHEN** a fill occurs
- **THEN** the detail page shows the fill and updated inventory within five seconds

### Requirement: Backtest comparison on bot detail

When a bot has a source backtest run, its detail page SHALL show a comparison card with source and paper net P&L, percentage returns, fees, fills, deltas, source and current capital, and a modified-since-creation indicator.

#### Scenario: Comparison shows both result sets
- **WHEN** the owner views a bot with a source backtest run
- **THEN** the page shows the source run and the source and paper net P&L, return percentage, fees, and fill counts

#### Scenario: Return units are percentages
- **WHEN** both the paper and backtest returns are five percent
- **THEN** the return delta is zero percentage points, not a mixed-unit value

#### Scenario: Modified source settings are indicated
- **WHEN** the bot's capital, fee, strategy configuration, or protective exits differ from the source snapshot
- **THEN** the card shows a modified-since-creation indicator and displays both source and current capital

#### Scenario: Zero capital is handled safely
- **WHEN** the paper or source capital is zero
- **THEN** the affected return is null and the comparison does not divide by zero

#### Scenario: Missing source metrics are handled
- **WHEN** the source run has null or empty metrics
- **THEN** the card shows an unavailable value rather than a fabricated number

#### Scenario: No paper fills are handled
- **WHEN** the paper bot has no fills yet
- **THEN** the card shows zero fills and does not error

### Requirement: Comparison refresh and source absence

The comparison SHALL include a caveat about differing periods, execution, risk limits, and edits, refresh with the bot's five-second polling, and SHALL not render when the source link is absent.

#### Scenario: Caveat is shown
- **WHEN** the comparison card renders
- **THEN** it states that periods, close-only backtest fills, live paper data, current risk limits, and post-creation edits can differ

#### Scenario: No source means no comparison
- **WHEN** the bot has no source backtest run
- **THEN** the comparison section is not shown

#### Scenario: Deleted source removes the comparison
- **WHEN** the source backtest run has been deleted
- **THEN** the link is cleared and the comparison section is not shown, while the bot remains usable

#### Scenario: Comparison refreshes with the bot
- **WHEN** the bot detail page refreshes
- **THEN** the paper values in the comparison refresh with the existing five-second polling

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

### Requirement: Global engine status bar

The authenticated layout SHALL show a status bar on every authenticated page with the engine heartbeat state, market-data feed state, and live-trading state. It SHALL poll the engine health endpoint at most once every ten seconds per tab, including across navigation, and SHALL distinguish live, REST fallback, idle, off, and unknown feed states. A missing heartbeat SHALL be a warning only; feed and live states SHALL then be unknown or explicitly last-known.

#### Scenario: Engine is up

- **WHEN** a signed-in user views any authenticated page and the heartbeat is fresh
- **THEN** the status bar shows the engine as up with its last-seen time

#### Scenario: Engine is down

- **WHEN** the heartbeat is missing or expired
- **THEN** the status bar shows the engine as unavailable with the last-seen time or "never", does not imply that bots are paused, and shows feed and live states as unknown or last-known

#### Scenario: Feed is live

- **WHEN** the heartbeat reports websocket mode without degradation
- **THEN** the status bar shows the feed as live

#### Scenario: Feed is degraded

- **WHEN** the heartbeat reports REST fallback or degradation
- **THEN** the status bar shows the REST fallback state and the reason

#### Scenario: Feed is idle

- **WHEN** the heartbeat reports a configured feed with no subscribed symbols
- **THEN** the status bar shows the feed as idle rather than off

#### Scenario: Feed is off

- **WHEN** the heartbeat reports the feed as not configured
- **THEN** the status bar shows the feed as off and indicates ticks use REST snapshots

#### Scenario: Partial feed status is unknown

- **WHEN** the heartbeat's market-data object is present but its configured or degraded state is malformed
- **THEN** the status bar shows the feed state as unknown rather than off or live

#### Scenario: Live trading is on

- **WHEN** the heartbeat reports live trading enabled
- **THEN** the status bar shows a prominent LIVE ON warning

#### Scenario: Live trading is off

- **WHEN** the heartbeat reports live trading disabled
- **THEN** the status bar shows that live orders are blocked by the gate rather than merely showing live trading as off

#### Scenario: Older engine omits status

- **WHEN** the heartbeat omits the feed or live-trading fields
- **THEN** the status bar shows those states as unknown rather than off

#### Scenario: Polling is bounded across navigation

- **WHEN** the user navigates between authenticated pages repeatedly
- **THEN** the status bar does not request the engine health endpoint more than once every ten seconds

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

### Requirement: Dashboard bot health

The dashboard SHALL show a triage section listing only the user's non-archived bots that need attention: running bots that are stale, bots paused by the engine with desired status running, and bots in an error state or running with a non-blank recorded error. Each entry SHALL show the bot name, state, last tick or last successful tick, and the reason, and SHALL refresh within five seconds.

#### Scenario: Engine-paused bot appears

- **WHEN** the engine pauses a running bot and leaves its desired status running
- **THEN** the triage section shows the bot with a paused-by-engine badge and the engine's last error as the reason

#### Scenario: Error bot appears

- **WHEN** a bot is in an error state or is running with a non-blank recorded error
- **THEN** the triage section shows the bot with an error badge and the recorded error as the reason

#### Scenario: Healthy bots are omitted

- **WHEN** a bot is running normally, idle, stopped, or paused by the user
- **THEN** it does not appear in the triage section

#### Scenario: Archived bots are omitted

- **WHEN** a bot is archived
- **THEN** it does not appear in the triage section

#### Scenario: Health changes are reflected

- **WHEN** a bot's health changes
- **THEN** the triage section reflects the change within five seconds

### Requirement: Stale bot detection

The dashboard SHALL mark a bot stale only when the bot is running with desired status running, the engine heartbeat is available, the engine start time is known, the engine has been up for at least the effective stale threshold, and the bot's last successful tick (or last tick or creation time) is older than that threshold. The effective threshold SHALL be the greater of 180 seconds and the bot interval plus 30 seconds.

#### Scenario: Stale bot appears

- **WHEN** a signed-in user opens the dashboard and a running bot has no successful tick within the effective stale threshold, and the engine has been up for at least that threshold
- **THEN** the bot appears with a stale badge, its last successful tick, and the reason

#### Scenario: Engine restart does not flash stale

- **WHEN** the engine restarts and its start time is within the stale threshold
- **THEN** a running bot with an older last successful tick is not shown as stale

#### Scenario: Older engine omits start time

- **WHEN** the heartbeat has no engine start time
- **THEN** the dashboard does not show a stale badge for any bot

#### Scenario: Unavailable heartbeat is not a stale signal

- **WHEN** the engine heartbeat is missing or expired
- **THEN** the dashboard does not mark bots stale solely because the engine is unavailable

#### Scenario: Threshold boundary is consistent

- **WHEN** a bot's last successful tick is exactly at the effective threshold
- **THEN** the dashboard applies the same boundary as the engine and does not disagree about whether the bot is stale

### Requirement: Bot health resume

The triage section SHALL let an owner resume a bot paused by the engine. A paper bot SHALL resume from the dashboard in one action; a live bot SHALL use the existing live confirmation flow and SHALL NOT resume without the exact confirmation phrase. A resume issued for a bot paused by the engine whose desired status is already running SHALL be recorded in the bot's activity log. The dashboard SHALL clear a pending resume state on the next poll and report failure or lack of engine confirmation.

#### Scenario: Resume a paper bot

- **WHEN** the owner resumes a paper bot paused by the engine from the dashboard
- **THEN** Laravel sets the desired status to running, publishes the start command, and reports the outcome

#### Scenario: Live resume uses confirmation

- **WHEN** the owner chooses to resume a live bot paused by the engine
- **THEN** the dashboard directs the owner to the bot page's confirmation flow and sends no start command from the triage section

#### Scenario: Resume is recorded

- **WHEN** a resume is issued for a bot paused by the engine whose desired status is already running
- **THEN** the bot's activity log records the user resume

#### Scenario: Resume failure is reported

- **WHEN** a resume fails
- **THEN** the dashboard shows the failure and keeps the bot's previous state visible

#### Scenario: Resume remains unconfirmed

- **WHEN** the next poll still shows the bot paused after a resume request
- **THEN** the dashboard clears the pending state and reports that the engine has not confirmed the resume

#### Scenario: Another user cannot resume

- **WHEN** a user attempts to resume a bot they do not own
- **THEN** the request is denied and no command is sent

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

The dashboard SHALL allow editing idle, stopped, and running paper bots. Editable fields SHALL be limited to name, capital allocation, fee rate, and strategy parameters. Running paper edits SHALL show a field diff and ladder/order preview before explicit confirmation; the server SHALL reject them without confirmation. Live running and paused bots SHALL NOT be editable. Identity fields SHALL be immutable, and protective exits SHALL remain separately editable.

#### Scenario: Edit an idle bot

- **WHEN** a user submits valid changes for an idle bot
- **THEN** the bot and its strategy configuration are updated and the change is recorded in the activity log

#### Scenario: Preview a running paper edit

- **WHEN** an owner changes fields on a running paper bot's edit page
- **THEN** the page shows a field-by-field diff with before and after values and the ladder and order preview, without persisting the change

#### Scenario: Edit a running paper bot after confirmation

- **WHEN** an owner submits valid changes for a running paper bot with explicit confirmation
- **THEN** the bot and its strategy configuration are updated, the field diff is recorded in the activity log, and a reconcile is requested when the change affects the strategy structure or capital allocation

#### Scenario: Reject a running paper edit without confirmation

- **WHEN** an owner submits changes for a running paper bot without explicit confirmation
- **THEN** the request is rejected and the bot is left unchanged

#### Scenario: Reject a running paper edit after a status change

- **WHEN** a bot becomes running paper between the edit page load and submission and the request has no confirmation
- **THEN** the request is rejected and the bot is left unchanged

#### Scenario: No-op running paper edit

- **WHEN** an owner submits a running paper edit with no changed fields
- **THEN** no update is persisted, no reconcile is requested, and no open orders are cancelled

#### Scenario: Running paper bot exposes an edit action

- **WHEN** an owner views a running paper bot's detail page
- **THEN** the page offers the edit action

#### Scenario: Reject editing a running bot

- **WHEN** a user attempts to edit a running live bot
- **THEN** the request is denied and the bot is left unchanged

#### Scenario: Reject editing a paused bot

- **WHEN** a user attempts to edit a paused bot
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

### Requirement: Grid activity view

The bot detail page SHALL link to a grid activity page for an owned bot. The page SHALL show a price-history line, each stored grid's levels clipped to its displayed lifetime, fills and cancels in the selected window, and controls to toggle each marker type. It SHALL offer 1-hour, 6-hour, 24-hour, and 7-day ranges, defaulting to 24 hours. Windows are half-open: events at or after the start and before the end belong to the window.

#### Scenario: Open grid activity from bot detail

- **WHEN** a user opens the detail page of an owned bot
- **THEN** a link to that bot's grid activity page is available

#### Scenario: Default window

- **WHEN** a user opens the grid activity page without selecting a range
- **THEN** the chart and summary cover the last 24 hours

#### Scenario: Change the window

- **WHEN** a user selects the 1-hour, 6-hour, or 7-day range
- **THEN** the price line, grid lifetimes, markers, and summary update to that window

#### Scenario: Grid lifetimes are visible

- **WHEN** several stored grids existed during the selected window
- **THEN** each grid's levels are drawn only for its displayed lifetime, and the current grid is visually distinguished

#### Scenario: Current ladder is not presented as trading after a stop

- **WHEN** a bot is paused or stopped but still has a current grid
- **THEN** that grid's levels end at the bot's last successful tick and are labeled as the current ladder rather than as active trading

#### Scenario: Fills and cancels are marked

- **WHEN** the selected window contains fills and canceled orders
- **THEN** each fill and cancel is plotted at its price and time, with controls to show or hide each marker type

#### Scenario: Accessible data fallback

- **WHEN** a user cannot use the chart
- **THEN** the page provides a table of the plotted price points, fills, and cancels

#### Scenario: Bounded output

- **WHEN** the window contains more markers or grids than the configured caps
- **THEN** the page shows the most recent entries up to each cap and states how many of the total are displayed

#### Scenario: Visible page refresh

- **WHEN** the grid activity page is visible and new fills or cancels occur
- **THEN** the chart and summary update without a full page reload within 30 seconds, and polling pauses while the tab is hidden

#### Scenario: Another user cannot open grid activity

- **WHEN** a user requests the grid activity page for a bot they do not own
- **THEN** the request is denied without revealing the bot's data

### Requirement: Grid activity churn summary

The page SHALL show a churn summary for the selected window. It SHALL include the fill count, all-cause cancel count, cancel-to-fill ratio (or "no fills" when there are none), stored grid revision count, median lifetime of grids replaced in the window, current spacing, and dynamic-grid spacing hysteresis. It SHALL label cancels as including pause, stop, operator, and grid-replacement causes, and state that restart re-arms are not stored revisions.

#### Scenario: Churn summary definitions

- **WHEN** the selected window contains grid activity
- **THEN** the summary distinguishes stored grid revisions from restart re-arms, excludes the current grid from the median lifetime, and labels the cancel count as including pause, stop, operator, and grid-replacement cancels

#### Scenario: No fills in the window

- **WHEN** the window contains cancels but no fills
- **THEN** the cancel-to-fill ratio is shown as "no fills"

#### Scenario: Spacing matches the strategy

- **WHEN** the bot uses a dynamic grid
- **THEN** the current spacing comes from the latest stored ladder and the configured spacing hysteresis is shown
- **AND WHEN** the bot uses a static grid
- **THEN** the current spacing comes from its strategy configuration and no hysteresis value is shown

### Requirement: Grid activity price history

The page SHALL obtain historical prices for the bot's exchange and symbol from public exchange data without private credentials. When the window cannot be served, it SHALL still render grids, fills, cancels, and counts, and SHALL state that price history is unavailable. When the window contains no grids, fills, or cancels, it SHALL show an empty state instead of a chart, whether or not price history is available.

#### Scenario: Public price history

- **WHEN** a user opens grid activity for a supported symbol and exchange
- **THEN** the price line is built from public historical candles and no API key or secret is required

#### Scenario: Price history unavailable

- **WHEN** historical prices cannot be retrieved for the selected window
- **THEN** the page shows the grids, fills, cancels, and summary without a price line and states that price history is unavailable

#### Scenario: Price history available but no activity

- **WHEN** the selected window contains candles but no grids, fills, or cancels
- **THEN** the page shows an empty state instead of a price-only chart
