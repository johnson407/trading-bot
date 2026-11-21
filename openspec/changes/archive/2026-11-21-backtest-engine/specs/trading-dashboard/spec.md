# Spec Delta

## MODIFIED Requirements

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
