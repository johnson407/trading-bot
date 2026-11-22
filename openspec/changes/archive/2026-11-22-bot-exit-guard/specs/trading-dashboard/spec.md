# Spec Delta

## ADDED Requirements

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

## MODIFIED Requirements

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
