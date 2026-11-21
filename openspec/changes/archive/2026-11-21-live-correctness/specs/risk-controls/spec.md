## MODIFIED Requirements

### Requirement: Per-bot exposure limits

Each bot SHALL enforce a configured maximum position size and a configured maximum number of open orders. The engine SHALL enforce these limits independently of strategy behavior and the user interface. Projected position SHALL include the remaining notional of open and pending buy orders. A sell SHALL be limited to base inventory not already reserved by open sell orders.

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

#### Scenario: Baseline for a bot without an override

- **WHEN** a bot has no explicit risk-limit override and the UTC day resets
- **THEN** that bot stores its own daily-loss baseline in its settings without overwriting the account baseline

### Requirement: Risk events

Every limit breach, automatic pause, kill-switch action, and live-mode rejection SHALL be recorded as a risk event with the bot, the rule, the observed value, and the configured limit. Risk events SHALL be visible in the activity log. An expected inventory deferral SHALL NOT be recorded as a risk event.

#### Scenario: Record a limit breach

- **WHEN** an order is blocked by an exposure limit
- **THEN** a risk event records the bot, the limit, the observed value, and the configured threshold

#### Scenario: Do not record an expected deferral

- **WHEN** a sell is deferred because its base inventory is not yet available
- **THEN** no risk event is recorded

## ADDED Requirements

### Requirement: Live fill fees

Live fills SHALL record the fee reported by the exchange, prorated across partial fills without double-counting a cumulative fee. When the exchange reports no fee, the bot's configured fee rate SHALL apply to the filled notional. Fees reduce realized profit and loss and increase the bot's recorded fees paid.

#### Scenario: Record an exchange fee

- **WHEN** a live fill is synchronized and the exchange reports a fee
- **THEN** the fill stores that fee and realized profit and loss reflects it

#### Scenario: Fall back to the configured rate

- **WHEN** a live fill is synchronized and the exchange reports no fee while the bot has a fee rate
- **THEN** the fee is computed from the filled notional and the bot fee rate

#### Scenario: Fee denominated in the base asset

- **WHEN** the exchange reports a fee in the base asset
- **THEN** the received or delivered base quantity is adjusted by the fee and the quote-equivalent fee is recorded

#### Scenario: Fee denominated in a third asset

- **WHEN** the exchange reports a fee in an asset other than the base or quote asset
- **THEN** the fee is valued at the fill price for quote accounting and fees paid

### Requirement: Risk limit configuration

Account-scope risk limits SHALL apply to every bot that does not have an explicit per-bot override. A user SHALL be able to view and update the account limits from the dashboard. Legacy bot-scope rows created automatically for every bot SHALL be removed.

#### Scenario: Apply account limits to a bot without an override

- **WHEN** a bot has no explicit override and an order would exceed the account position or open-order limit
- **THEN** the order is blocked by the account limit

#### Scenario: Update account limits

- **WHEN** a user saves valid account limits
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
