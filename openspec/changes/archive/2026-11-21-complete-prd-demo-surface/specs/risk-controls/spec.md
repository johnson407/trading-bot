# Spec Delta

## ADDED Requirements

### Requirement: Live confirmation names bot and exchange

Creating or starting a live bot SHALL require an explicit confirmation phrase containing both the bot name and the exchange. The exact required phrase SHALL be `<bot name> on <exchange>`. The system SHALL reject a missing or mismatched phrase at creation and at start.

#### Scenario: Confirm a live bot at creation

- **WHEN** a user creates a live bot with the exact phrase `<bot name> on <exchange>`
- **THEN** the bot is created and remains subject to the live configuration gate

#### Scenario: Reject a live bot without confirmation

- **WHEN** a user submits a live bot with a missing or wrong confirmation phrase
- **THEN** the form reports a confirmation error and no bot is created

#### Scenario: Confirm starting a live bot

- **WHEN** a user starts a live bot and supplies the exact phrase
- **THEN** the start proceeds to the configuration gate and balance check

#### Scenario: Reject starting without the exchange

- **WHEN** a user starts a live bot with a confirmation that names only the bot
- **THEN** the start is rejected and the confirmation error is displayed
