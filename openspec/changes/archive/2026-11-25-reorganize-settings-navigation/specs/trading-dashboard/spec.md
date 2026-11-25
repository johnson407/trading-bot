# Spec Delta

## ADDED Requirements

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

## MODIFIED Requirements

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

### Requirement: Kill-switch access

The dashboard SHALL expose the account-wide kill-switch from the bot list as a danger-styled control beside Create bot, and SHALL require explicit confirmation before activating it. Activation SHALL write `desired_status` paused and publish a `kill_switch` command. If the engine heartbeat is missing or expired or publish fails, Laravel SHALL also cancel open live orders in that same action.

#### Scenario: Locate the kill switch

- **WHEN** a user opens the bot list
- **THEN** a danger-styled Kill switch control is shown next to Create bot

#### Scenario: Activate from the dashboard

- **WHEN** a user confirms the kill-switch after opening it from the bot list
- **THEN** all of the user's bots are paused and their open orders are canceled

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
