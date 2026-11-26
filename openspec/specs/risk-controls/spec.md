# risk-controls Specification

## Purpose

Defines the safeguards that limit trading exposure, stop bots when losses exceed configured thresholds, and let a user immediately halt all trading.

## Requirements

### Requirement: Per-bot exposure limits

Each bot SHALL enforce a configured maximum position size and maximum number of open orders, independently of strategy behavior and the user interface. Projected position SHALL include the remaining notional of open and pending buys, and a sell SHALL be limited to base inventory not reserved by open sells. Concurrent ticks SHALL NOT reserve the same base inventory.

#### Scenario: Block an order above the position cap

- **WHEN** placing an order would take the bot's position above its configured maximum position size
- **THEN** the order is not placed and a risk event is recorded

#### Scenario: Block an order above the open-order cap

- **WHEN** placing an order would exceed the bot's configured maximum number of open orders
- **THEN** the order is not placed and a risk event is recorded

#### Scenario: Count open buys toward the position cap

- **WHEN** a bot already has open buy orders whose remaining notional plus its inventory would exceed the position cap
- **THEN** an additional buy that would cross the cap is not placed

#### Scenario: Defer an uncovered sell

- **WHEN** a strategy requests a sell larger than the base inventory not reserved by open sells
- **THEN** the sell is not placed, the decision is reported as `risk.insufficient_inventory`, and no risk event is recorded for the expected deferral

#### Scenario: Serialize concurrent sell reservations

- **WHEN** two ticks attempt to sell against the same unreserved base inventory at the same time
- **THEN** only one order is created and the other intent is deferred

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

### Requirement: Daily loss limit resolution

The engine SHALL resolve the effective daily-loss limit from an explicit bot-scope override first, then the account-scope row, then the built-in default, evaluating each column independently. It SHALL NOT place orders when the daily-loss limit or the account daily baseline cannot be read; it SHALL record a clear error for the bot instead.

#### Scenario: Bot override wins

- **WHEN** a bot has an explicit bot-scope daily-loss limit and the account also has a limit
- **THEN** the engine enforces the bot-scope limit

#### Scenario: NULL bot override falls back to the account limit

- **WHEN** a bot-scope row exists but its daily-loss limit is NULL and the account row has a limit
- **THEN** the engine enforces the account limit instead of the built-in default

#### Scenario: No configured limits fall back to the default

- **WHEN** neither a bot-scope nor an account-scope daily-loss limit is stored
- **THEN** the engine enforces the built-in default daily-loss limit

#### Scenario: Unreadable limit data fails closed

- **WHEN** the daily-loss limit or account daily baseline cannot be read from the database
- **THEN** the bot records an error and does not place orders on that tick

### Requirement: Kill-switch

The system SHALL provide an account-wide kill-switch that cancels the user's open orders and pauses those bots. Laravel SHALL write `desired_status` paused and publish `kill_switch`. The engine SHALL cancel without waiting for a tick and honor paused desired status at least once per second if the stream is down. If publish fails or the heartbeat is missing or expired, Laravel SHALL cancel live open orders in the same action via `CancelBotOrders` and SHALL NOT write `status` or `last_error`.

#### Scenario: Activate the kill-switch

- **WHEN** a user activates the kill-switch
- **THEN** all open orders for that user's bots are canceled and all of those bots are paused

#### Scenario: Kill-switch reports failures

- **WHEN** an open order cannot be canceled during a kill-switch
- **THEN** the failure is recorded per bot and surfaced to the user without stopping the rest of the cancellation work

#### Scenario: Kill-switch does not wait for a tick

- **WHEN** a user activates the kill-switch in the middle of a bot's tick interval
- **THEN** the engine begins cancellation immediately rather than waiting for the next tick

#### Scenario: Kill-switch cancels live orders when the engine is unavailable

- **WHEN** a user activates the kill-switch and the engine heartbeat is missing or expired, or publishing the command fails
- **THEN** Laravel still marks the bots' desired status paused and, in the same action, cancels open orders on that user's live bots, recording per-order failures without stopping the rest of the work

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

### Requirement: Paper mode default

The system SHALL default every new bot to paper mode and SHALL require an explicit user action to enable live trading. No automated process SHALL promote a bot from paper to live mode.

#### Scenario: No automatic live promotion

- **WHEN** a paper bot meets its strategy conditions and runs successfully
- **THEN** it remains in paper mode until a user explicitly changes its mode

### Requirement: Risk events

Every limit breach, automatic pause, kill-switch action, and live-mode rejection SHALL be recorded as a risk event with the bot, the rule, the observed value, and the configured limit. Risk events SHALL be visible in the activity log. An expected inventory deferral SHALL NOT be recorded as a risk event.

#### Scenario: Record a limit breach

- **WHEN** an order is blocked by an exposure limit
- **THEN** a risk event records the bot, the limit, the observed value, and the configured threshold

#### Scenario: Do not record an expected deferral

- **WHEN** a sell is deferred because its base inventory is not yet available
- **THEN** no risk event is recorded

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

### Requirement: Live fill fees

Live fills SHALL record the fee reported by the exchange, prorated across partial fills without double-counting a cumulative fee. An unreported fee SHALL fall back to the bot's configured fee rate, an explicit zero fee SHALL remain zero, and a negative fee SHALL be credited as a rebate. Fees increase the bot's recorded fees paid and reduce realized profit and loss in quote-equivalent terms.

#### Scenario: Record an exchange fee

- **WHEN** a live fill is synchronized and the exchange reports a fee
- **THEN** the fill stores that fee and realized profit and loss reflects it

#### Scenario: Fall back to the configured rate

- **WHEN** a live fill is synchronized and the exchange reports no fee while the bot has a fee rate
- **THEN** the fee is computed from the filled notional and the bot fee rate

#### Scenario: Keep an explicit zero fee

- **WHEN** the exchange reports a fee of zero while the bot has a configured fee rate
- **THEN** the recorded fee is zero and no fallback is applied

#### Scenario: Credit a rebate

- **WHEN** the exchange reports a negative fee
- **THEN** realized profit and loss is increased by the rebate and fees paid is reduced

#### Scenario: Fee denominated in the base asset

- **WHEN** the exchange reports a fee in the base asset
- **THEN** the received or delivered base quantity is adjusted by the fee and the quote-equivalent fee is recorded

#### Scenario: Fee denominated in a third asset

- **WHEN** the exchange reports a fee in an asset other than the base or quote asset
- **THEN** the fee is valued at the fill price for quote accounting and fees paid

### Requirement: Risk limit configuration

Account-scope risk limits SHALL apply to every bot that does not have an explicit per-bot override. A user SHALL be able to view and update the account limits from the Settings area. Legacy bot-scope rows created automatically for every bot SHALL be removed.

#### Scenario: Apply account limits to a bot without an override

- **WHEN** a bot has no explicit override and an order would exceed the account position or open-order limit
- **THEN** the order is blocked by the account limit

#### Scenario: Update account limits

- **WHEN** a user saves valid account limits from the Settings area
- **THEN** the new limits are stored and applied to every bot without an override

#### Scenario: Reject invalid limits

- **WHEN** a user submits a non-positive position or daily-loss limit, or an out-of-range open-order limit
- **THEN** the form reports validation errors and the stored limits are unchanged

### Requirement: Shared-account balance safety

When more than one live bot uses the same exchange credential, or the same user has multiple live bots without a credential, the system SHALL skip refreshing per-bot inventory from the whole-wallet balance and SHALL record why the sync was skipped. Reconciliation SHALL skip the whole-wallet balance comparison on the same condition.

#### Scenario: Skip shared-account balance sync

- **WHEN** scheduled balance synchronization runs and another live bot shares the account
- **THEN** the bot's inventory is left unchanged and a `bot.balance_sync_skipped` activity with `reason: shared_account` is recorded

#### Scenario: Reconcile a shared account

- **WHEN** a running live bot on a shared account is reconciled
- **THEN** the whole-wallet balance comparison is skipped and no reconciliation conflict is raised for the shared balance

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

### Requirement: Protective exit configuration

A protective exit rule SHALL be optional and SHALL be disabled when its value is blank. Stop-loss and take-profit percentages SHALL be greater than zero and less than one hundred. Range bounds SHALL be positive, and a configured range low SHALL be less than its range high. Invalid rules SHALL NOT replace previously stored rules.

#### Scenario: Valid rules are accepted

- **WHEN** a user saves a stop-loss below one hundred percent, a positive take-profit, and a range whose low is below its high
- **THEN** the rules are stored for that bot and apply on its next tick

#### Scenario: Invalid rules are rejected

- **WHEN** a user submits a stop-loss of one hundred percent or more, a non-positive percentage or range bound, or a range low at or above its high
- **THEN** the request reports validation errors and the stored rules are unchanged

### Requirement: Per-order live risk gates

The engine SHALL apply the same runtime risk limits immediately before every live order place, not only at bot start. Default and production configuration SHALL leave `TRADING_LIVE_ENABLED` false unless an operator explicitly enables it for a named environment.

#### Scenario: Risk gates apply to each live place

- **WHEN** a running live bot attempts to place an order that would breach a risk limit
- **THEN** the engine blocks the place, does not send it to the exchange, and records a risk event

#### Scenario: Default live gate is closed

- **WHEN** the default or production composition starts without an explicit operator override
- **THEN** `TRADING_LIVE_ENABLED` is false
