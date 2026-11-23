# Spec Delta

## MODIFIED Requirements

### Requirement: Daily loss limit

Each bot SHALL enforce a configured daily loss limit, and the account SHALL support a global daily loss limit. Enforcement SHALL run in the Go engine when a tick is evaluated. When realized plus unrealized loss for the current day reaches the applicable limit, the engine SHALL pause the affected bot, cancel its open orders, and record a risk event. Laravel SHALL store the configured limits and SHALL NOT compute or apply daily-loss pauses after cutover.

#### Scenario: Per-bot daily limit reached

- **WHEN** a bot's loss for the current day reaches its configured daily loss limit
- **THEN** that bot pauses and its open orders are canceled

#### Scenario: Global daily limit reached

- **WHEN** the account's loss for the current day reaches the global daily loss limit
- **THEN** every running bot pauses and their open orders are canceled

#### Scenario: Loss window reset

- **WHEN** the configured daily reset boundary passes
- **THEN** the day's loss total resets and paused bots remain paused until explicitly resumed

#### Scenario: Baseline for a bot without an override

- **WHEN** a bot has no explicit risk-limit override and the UTC day resets
- **THEN** that bot stores its own daily-loss baseline in its settings without overwriting the account baseline

### Requirement: Kill-switch

The system SHALL provide an account-wide kill-switch that cancels all open orders for all of the user's bots and pauses those bots. Laravel SHALL mark those bots paused in Postgres and publish a `kill_switch` command. The engine SHALL execute the cancellations without waiting for the next scheduled tick. If the command stream is unavailable, the engine SHALL still honor the paused rows on its next status refresh, at least once per second.

#### Scenario: Activate the kill-switch

- **WHEN** a user activates the kill-switch
- **THEN** all open orders for that user's bots are canceled and all of those bots are paused

#### Scenario: Kill-switch reports failures

- **WHEN** an open order cannot be canceled during a kill-switch
- **THEN** the failure is recorded per bot and surfaced to the user without stopping the rest of the cancellation work

#### Scenario: Kill-switch does not wait for a tick

- **WHEN** a user activates the kill-switch in the middle of a bot's tick interval
- **THEN** the engine begins cancellation immediately rather than waiting for the next tick

### Requirement: Live-mode safeguards

Live mode SHALL require an explicit confirmation that names the bot and the exchange, enforced by Laravel before a command is published. Before a live bot starts or places its first order, the engine SHALL verify that `TRADING_LIVE_ENABLED` is true and that the exchange reports sufficient balance; a closed gate or shortfall SHALL prevent the start. A closed live gate SHALL pause a live bot and SHALL NOT run it as paper.

#### Scenario: Confirm live mode

- **WHEN** a user switches a bot from paper to live mode
- **THEN** the system requires an explicit confirmation before the mode change is saved

#### Scenario: Insufficient balance

- **WHEN** a live bot is started but the exchange reports a balance below the configured capital allocation
- **THEN** the bot does not start and the shortfall is reported

#### Scenario: Closed live gate does not fall back to paper

- **WHEN** a live bot is running or is started and `TRADING_LIVE_ENABLED` is false
- **THEN** the engine does not place live orders and does not convert the bot to paper mode

### Requirement: Per-bot protective exits

Each bot SHALL support optional stop-loss, take-profit, and price-range exit rules. The Go engine SHALL evaluate them on every tick after the mark is updated and before daily-loss and strategy evaluation. A trigger SHALL pause the bot, cancel open and pending orders, record a risk event, and leave base inventory unchanged. Laravel SHALL store the configuration and SHALL NOT evaluate exits after cutover.

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

#### Scenario: Resume while still beyond the threshold

- **WHEN** a user resumes a bot that is still beyond its protective-exit threshold
- **THEN** the engine pauses it again on the next tick
