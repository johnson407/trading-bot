# risk-controls Specification

## Purpose

Defines the safeguards that limit trading exposure, stop bots when losses exceed configured thresholds, and let a user immediately halt all trading.

## Requirements

### Requirement: Per-bot exposure limits

Each bot SHALL enforce a configured maximum position size and a configured maximum number of open orders. The engine SHALL enforce these limits independently of strategy behavior and the user interface.

#### Scenario: Block an order above the position cap

- **WHEN** placing an order would take the bot's position above its configured maximum position size
- **THEN** the order is not placed and a risk event is recorded

#### Scenario: Block an order above the open-order cap

- **WHEN** placing an order would exceed the bot's configured maximum number of open orders
- **THEN** the order is not placed and a risk event is recorded

### Requirement: Daily loss limit

Each bot SHALL enforce a configured daily loss limit, and the account SHALL support a global daily loss limit. When realized plus unrealized loss for the current day reaches the applicable limit, the affected bot SHALL pause automatically, cancel its open orders, and record a risk event.

#### Scenario: Per-bot daily limit reached

- **WHEN** a bot's loss for the current day reaches its configured daily loss limit
- **THEN** that bot pauses and its open orders are canceled

#### Scenario: Global daily limit reached

- **WHEN** the account's loss for the current day reaches the global daily loss limit
- **THEN** every running bot pauses and their open orders are canceled

#### Scenario: Loss window reset

- **WHEN** the configured daily reset boundary passes
- **THEN** the day's loss total resets and paused bots remain paused until explicitly resumed

### Requirement: Kill-switch

The system SHALL provide an account-wide kill-switch that cancels all open orders for all of the user's bots and pauses those bots. The kill-switch SHALL take effect without waiting for the next scheduled tick.

#### Scenario: Activate the kill-switch

- **WHEN** a user activates the kill-switch
- **THEN** all open orders for that user's bots are canceled and all of those bots are paused

#### Scenario: Kill-switch reports failures

- **WHEN** an open order cannot be canceled during a kill-switch
- **THEN** the failure is recorded per bot and surfaced to the user without stopping the rest of the cancellation work

### Requirement: Live-mode safeguards

Live mode SHALL require an explicit confirmation that names the bot and the exchange. Before a live bot starts or places its first order, the system SHALL verify that the exchange reports sufficient balance for the configured capital allocation; insufficient balance SHALL prevent the start.

#### Scenario: Confirm live mode

- **WHEN** a user switches a bot from paper to live mode
- **THEN** the system requires an explicit confirmation before the mode change is saved

#### Scenario: Insufficient balance

- **WHEN** a live bot is started but the exchange reports a balance below the configured capital allocation
- **THEN** the bot does not start and the shortfall is reported

### Requirement: Paper mode default

The system SHALL default every new bot to paper mode and SHALL require an explicit user action to enable live trading. No automated process SHALL promote a bot from paper to live mode.

#### Scenario: No automatic live promotion

- **WHEN** a paper bot meets its strategy conditions and runs successfully
- **THEN** it remains in paper mode until a user explicitly changes its mode

### Requirement: Risk events

Every limit breach, automatic pause, kill-switch action, and live-mode rejection SHALL be recorded as a risk event with the bot, the rule, the observed value, and the configured limit. Risk events SHALL be visible in the activity log.

#### Scenario: Record a limit breach

- **WHEN** an order is blocked by an exposure limit
- **THEN** a risk event records the bot, the limit, the observed value, and the configured threshold

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
