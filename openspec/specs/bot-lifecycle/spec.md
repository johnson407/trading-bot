# bot-lifecycle Specification

## Purpose

Lets an owner retire a bot without destroying its trading history, and bring it back later, while keeping archived bots out of active trading, listings, and new analysis.

## Requirements

### Requirement: Archive a stopped bot

The system SHALL let a bot's owner archive the bot only when its runtime status and desired status are both `stopped` and it has no pending, open, or partially filled orders. For a live bot, the system SHALL also require a fresh engine heartbeat. Archiving SHALL preserve the bot row and all related history while hiding the bot from active bot listings, selectors, operational counts, and the kill switch.

#### Scenario: Archive an eligible paper bot

- **WHEN** the owner archives a paper bot whose status and desired status are `stopped` and that has no open orders
- **THEN** the bot is archived, disappears from the active bot list, and appears in the archived view

#### Scenario: Archive an eligible live bot

- **WHEN** the owner archives a stopped live bot that has no open orders and the engine heartbeat is fresh
- **THEN** the bot is archived

#### Scenario: Reject archiving a live bot without a fresh heartbeat

- **WHEN** the owner attempts to archive a stopped live bot while the engine heartbeat is missing or expired
- **THEN** the request is rejected with an error explaining that the engine must be available and the bot remains active

#### Scenario: Reject archiving a bot that is not stopped

- **WHEN** the owner attempts to archive a bot that is running, paused, in error, or has a desired status other than `stopped`
- **THEN** the request is rejected with an error explaining that the bot must be stopped first and the bot remains active

#### Scenario: Reject archiving with open orders

- **WHEN** the owner attempts to archive a stopped bot that still has a pending, open, or partially filled order
- **THEN** the request is rejected and the bot remains active

#### Scenario: Another user cannot archive

- **WHEN** a user attempts to archive a bot they do not own
- **THEN** the request is denied and the bot's archive state is unchanged

### Requirement: Archived bots are inert

An archived bot SHALL NOT be started, paused, stopped, edited, or selected for a new backtest or sweep. Its recorded status, desired status, inventory, grid, and strategy state SHALL remain unchanged while archived. The trading engine SHALL NOT apply control commands to it, select it for a tick, reconcile it, or synchronize its live orders.

#### Scenario: Archived bot cannot be controlled

- **WHEN** a request attempts to start, pause, stop, edit, cancel an order for, or change the exit guard of an archived bot
- **THEN** the request is denied and no engine command is published

#### Scenario: Stale start does not resurrect an archived bot

- **WHEN** a start request that loaded the bot before it was archived attempts to write the desired status after the archive commits
- **THEN** the write affects no row, no start command is published, and the archived bot's status and desired status remain `stopped`

#### Scenario: Engine ignores an archived bot

- **WHEN** the engine selects bots for a tick, reconciliation, a stale check, or live-order synchronization
- **THEN** bots with a non-null archive timestamp are not selected, and a queued start for an archived bot does not change its status

#### Scenario: Archived bot is not offered for new analysis

- **WHEN** an owner opens the create page for a backtest or parameter sweep
- **THEN** archived bots are not available for selection

#### Scenario: Archived bot balances remain account exposure

- **WHEN** the engine evaluates account-level daily loss for the owner's active bots
- **THEN** archived bots' realized profit and loss and inventory are still included in the account total

### Requirement: Restore an archived bot

The system SHALL let the bot's owner restore an archived bot. Restoring SHALL return the bot to the active bot list in its stopped state and SHALL NOT resume trading or change its grid, inventory, orders, or strategy state. Restoring a bot that is not archived SHALL be rejected.

#### Scenario: Restore from the archived view

- **WHEN** the owner restores an archived bot
- **THEN** the bot leaves the archived view, appears again in the active bot list, and remains stopped

#### Scenario: Restore does not resume trading

- **WHEN** an archived bot is restored
- **THEN** its desired status remains `stopped`, no start command is published, and no order is placed

#### Scenario: Restore a bot that is not archived

- **WHEN** the owner submits a restore request for a bot that is not archived
- **THEN** the request is rejected and the bot is unchanged

#### Scenario: Another user cannot restore

- **WHEN** a user attempts to restore a bot they do not own
- **THEN** the request is denied and the bot remains archived

### Requirement: Archive preserves history

Archiving SHALL NOT delete or detach orders, fills, grids, strategy configuration, inventory ledger entries, risk events, activity logs, alerts, backtest runs, or sweeps. Views that reference the archived bot SHALL continue to display its name.

#### Scenario: Related records remain

- **WHEN** a bot with orders, fills, activity, alerts, and backtest runs is archived
- **THEN** every related record still exists and still references the bot

#### Scenario: Historical views keep the bot name

- **WHEN** a user views activity, alerts, backtest runs, or sweeps created before the bot was archived
- **THEN** those entries still show the archived bot's name

#### Scenario: Archived bot references do not link to a missing detail page

- **WHEN** dashboard recent activity or the alert inbox references an archived bot
- **THEN** the bot name is shown without a link to the archived bot's detail page

#### Scenario: Archive is reversible without data loss

- **WHEN** an archived bot is restored
- **THEN** its previous orders, fills, grid, inventory, strategy state, activity, alerts, and backtests are still available
