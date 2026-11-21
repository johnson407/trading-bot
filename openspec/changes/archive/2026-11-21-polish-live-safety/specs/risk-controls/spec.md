# Spec Delta

## MODIFIED Requirements

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
