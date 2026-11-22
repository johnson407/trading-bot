# Spec Delta

## ADDED Requirements

### Requirement: Per-bot protective exits

Each bot SHALL support optional stop-loss, take-profit, and price-range exit rules. A running bot SHALL evaluate them on every tick after its mark price is updated and before the daily-loss check and strategy tick. A trigger SHALL pause the bot, cancel its open and pending orders, record a risk event, and leave base inventory unchanged.

#### Scenario: Stop-loss triggers

- **WHEN** a bot holds base inventory and the mark price is at or below its average entry price reduced by the configured stop-loss percentage
- **THEN** the bot pauses, its open and pending orders are canceled, and a `risk.stop_loss_triggered` risk event records the observed price and configured threshold

#### Scenario: Take-profit triggers

- **WHEN** a bot holds base inventory and the mark price is at or above its average entry price increased by the configured take-profit percentage
- **THEN** the bot pauses, its open and pending orders are canceled, and a `risk.take_profit_triggered` risk event records the observed price and configured threshold

#### Scenario: Range exit triggers on either bound

- **WHEN** the mark price is at or below the configured range low or at or above the configured range high
- **THEN** the bot pauses, its open and pending orders are canceled, and a `risk.range_exit_triggered` risk event records the observed price and the crossed bound

#### Scenario: Rules do not trigger inside their thresholds

- **WHEN** every enabled rule is satisfied and the mark price is inside all configured thresholds
- **THEN** the bot remains running and the strategy is evaluated normally

#### Scenario: Stop-loss takes precedence over a range exit

- **WHEN** the mark price is below both the stop-loss threshold and the range low at the same evaluation
- **THEN** the bot pauses once and records the stop-loss event

#### Scenario: Disabled rules are ignored

- **WHEN** a bot has no configured exit rules, or a rule is blank
- **THEN** that rule does not trigger and the bot continues running

#### Scenario: Protective exit never liquidates inventory

- **WHEN** a protective exit pauses a bot that holds base inventory
- **THEN** the bot's base and quote inventory quantities are unchanged by the exit and only open orders are canceled

#### Scenario: Paused bot stays paused

- **WHEN** a bot has paused from a protective exit and the price remains beyond the threshold on later ticks
- **THEN** the bot does not resume automatically and does not place new orders

#### Scenario: Exit cancellation failure is reported

- **WHEN** an open order cannot be canceled while pausing for a protective exit
- **THEN** a `risk.exit_cancel_failed` risk event is recorded and the remaining orders are still canceled

### Requirement: Protective exit configuration

A protective exit rule SHALL be optional and SHALL be disabled when its value is blank. Stop-loss and take-profit percentages SHALL be greater than zero and less than one hundred. Range bounds SHALL be positive, and a configured range low SHALL be less than its range high. Invalid rules SHALL NOT replace previously stored rules.

#### Scenario: Valid rules are accepted

- **WHEN** a user saves a stop-loss below one hundred percent, a positive take-profit, and a range whose low is below its high
- **THEN** the rules are stored for that bot and apply on its next tick

#### Scenario: Invalid rules are rejected

- **WHEN** a user submits a stop-loss of one hundred percent or more, a non-positive percentage or range bound, or a range low at or above its high
- **THEN** the request reports validation errors and the stored rules are unchanged
