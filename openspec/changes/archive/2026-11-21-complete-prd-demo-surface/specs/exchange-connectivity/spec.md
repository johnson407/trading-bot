# Spec Delta

## ADDED Requirements

### Requirement: Supported exchange allowlist

The system SHALL expose a configured allowlist of exchanges that a bot may select. The allowlist SHALL default to Binance and SHALL be configurable without code changes. Bot creation SHALL reject an exchange that is not on the allowlist with a field-level validation error.

#### Scenario: Default allowlist

- **WHEN** the application runs without an exchange allowlist override
- **THEN** Binance is the only selectable exchange

#### Scenario: Reject an unsupported exchange

- **WHEN** a user submits a bot with an exchange that is not on the allowlist
- **THEN** the form reports an error on the exchange field and no bot is created

#### Scenario: Widen the allowlist

- **WHEN** an operator adds another CCXT exchange to the allowlist
- **THEN** that exchange appears in the bot creation form and can be selected

### Requirement: Per-bot exchange routing

Market data, balances, and order operations for a bot SHALL use the exchange stored on that bot, not a process-wide default. Two bots configured for different exchanges SHALL operate against their respective exchanges.

#### Scenario: Use the bot's exchange for market data

- **WHEN** the engine fetches market data for a bot
- **THEN** the request is made against the exchange stored on that bot

#### Scenario: Use the bot's exchange for live orders

- **WHEN** a live bot places or cancels an order
- **THEN** the request is sent through the client bound to that bot's exchange

#### Scenario: Mixed exchanges in one account

- **WHEN** a user runs bots on two different allowlisted exchanges
- **THEN** each bot reads prices and manages orders on its own exchange

### Requirement: Paper market data uses the selected exchange

Paper-mode market data SHALL be fetched from the bot's selected exchange using public endpoints only. Paper mode SHALL never send private or order-placing requests.

#### Scenario: Paper bot on a non-default exchange

- **WHEN** a paper bot selects a non-default allowlisted exchange
- **THEN** its simulated fills are driven by that exchange's public market data and no private request is sent
