# Spec Delta

## MODIFIED Requirements

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
