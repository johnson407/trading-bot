# Spec Delta

## MODIFIED Requirements

### Requirement: Alert on operational incidents

The system SHALL create an alert when a covered incident occurs: a bot pauses from the daily-loss limit, consecutive exchange failures, a stale tick loop, a reconciliation conflict, the live-trading gate, or a protective exit; the user activates the kill-switch; or an order cancellation fails while pausing. It SHALL NOT create an alert for routine order, fill, balance, or expected inventory-deferral events.

#### Scenario: Daily-loss pause creates an alert

- **WHEN** a bot pauses because its per-bot or account-wide daily loss limit is reached
- **THEN** an alert is created for the bot's owner with the daily-loss incident type

#### Scenario: Consecutive failure pause creates an alert

- **WHEN** a bot pauses after reaching the configured consecutive exchange failure threshold
- **THEN** an alert is created for the bot's owner with the exchange-failure incident type

#### Scenario: Stale tick pause creates an alert

- **WHEN** the health check pauses a bot because its tick loop is stale
- **THEN** an alert is created for the bot's owner with the stale-bot incident type

#### Scenario: Reconciliation conflict creates an alert

- **WHEN** reconciliation pauses a bot after detecting a conflict with the exchange
- **THEN** a critical alert is created for the bot's owner with the reconciliation incident type

#### Scenario: Live-trading gate creates an alert

- **WHEN** a live bot pauses because live trading is disabled
- **THEN** a critical alert is created for the bot's owner with the live-gate incident type

#### Scenario: Kill-switch activation creates an account alert

- **WHEN** a user activates the account-wide kill-switch
- **THEN** an account-level alert is created for that user without a bot association

#### Scenario: Protective exit creates an alert

- **WHEN** a bot pauses because a stop-loss, take-profit, or range exit triggers
- **THEN** an alert is created for the bot's owner with the matching protective-exit incident type

#### Scenario: Cancellation failure while pausing creates an alert

- **WHEN** an open order cannot be canceled while a bot is pausing for a risk breach, including a protective exit
- **THEN** an alert is created for the bot's owner with the cancellation-failure incident type

#### Scenario: Expected inventory deferral does not alert

- **WHEN** a sell is deferred because the bot does not yet hold enough base inventory
- **THEN** no alert is created

#### Scenario: Routine trading activity does not alert

- **WHEN** an order is placed, filled, or canceled normally, or a balance sync succeeds
- **THEN** no alert is created
