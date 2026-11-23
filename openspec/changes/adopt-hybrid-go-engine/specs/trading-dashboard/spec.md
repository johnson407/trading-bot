# Spec Delta

## MODIFIED Requirements

### Requirement: Bot controls

The dashboard SHALL allow a bot's owner to start, pause, and stop the bot, and SHALL show the outcome of those actions. Start, pause, and stop SHALL record the desired status in Postgres and publish the corresponding engine command. The dashboard SHALL continue to refresh by polling at most every five seconds and SHALL NOT require a browser WebSocket. Starting or resuming a live bot SHALL require explicit confirmation before the command is sent.

#### Scenario: Pause from the dashboard

- **WHEN** a user pauses a running bot
- **THEN** the dashboard confirms the action and the bot stops placing new orders

#### Scenario: Confirm a live action

- **WHEN** a user starts or resumes a live bot
- **THEN** the dashboard requires explicit confirmation before the action is sent

#### Scenario: Report a failed control action

- **WHEN** a control action fails
- **THEN** the dashboard shows the failure and the bot's previous state remains visible

### Requirement: Dashboard overview

The dashboard SHALL present an overview rather than a placeholder. It SHALL show the user's bots by state, aggregate realized and unrealized profit and loss, and recent activity, and SHALL refresh within five seconds. It SHALL also show whether the trading engine heartbeat is currently available.

#### Scenario: Open the dashboard

- **WHEN** a signed-in user opens the dashboard
- **THEN** bot counts, aggregate profit and loss, and recent activity are shown

#### Scenario: Dashboard freshness

- **WHEN** a bot's state or profit and loss changes
- **THEN** the dashboard reflects the change within five seconds

#### Scenario: Engine availability is visible

- **WHEN** the engine heartbeat is missing or expired
- **THEN** the dashboard shows the engine as unavailable

### Requirement: Kill-switch access

The dashboard SHALL expose the account-wide kill-switch and SHALL require explicit confirmation before activating it. Activation SHALL mark the user's bots paused in Postgres and publish a `kill_switch` command so the engine can cancel open orders without waiting for the next tick.

#### Scenario: Activate from the dashboard

- **WHEN** a user confirms the kill-switch
- **THEN** all of the user's bots are paused and their open orders are canceled
