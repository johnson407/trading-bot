# Spec Delta

## ADDED Requirements

### Requirement: Primary navigation

The authenticated layout SHALL link to the dashboard, the bot list, exchange credentials, and the kill switch from every authenticated page, on both desktop and responsive menus.

#### Scenario: Reach the bot list

- **WHEN** a signed-in user views any authenticated page
- **THEN** a navigation link to the bot list is available without typing a URL

#### Scenario: Reach credentials and the kill switch

- **WHEN** a signed-in user opens the navigation menu
- **THEN** links to exchange credentials and the kill switch are present

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

### Requirement: Bot editing

The dashboard SHALL allow editing an idle or stopped bot. Editable fields SHALL be limited to the bot name, capital allocation, fee rate, and strategy parameters. The symbol, exchange, trading mode, and strategy key SHALL be immutable after creation. Running or paused bots SHALL NOT be editable.

#### Scenario: Edit an idle bot

- **WHEN** a user submits valid changes for an idle bot
- **THEN** the bot and its strategy configuration are updated and the change is recorded in the activity log

#### Scenario: Reject editing a running bot

- **WHEN** a user attempts to edit a running or paused bot
- **THEN** the request is denied and the bot is left unchanged

#### Scenario: Identity fields cannot change

- **WHEN** an update request includes a symbol, exchange, mode, or strategy key
- **THEN** those values are ignored or rejected and the stored identity is unchanged
