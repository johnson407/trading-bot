# exchange-connectivity Specification

## Purpose

Defines how the system connects to cryptocurrency exchanges, how paper trading simulates execution without private access, how live orders are placed and canceled, and how exchange credentials and failures are handled.

## Requirements

### Requirement: Exchange selection

The system SHALL support multiple exchanges through one connectivity boundary, SHALL use Binance as the default exchange, and SHALL allow a bot to select any exchange for which the underlying exchange library provides support. Each bot SHALL store its selected exchange.

#### Scenario: Create a bot with the default exchange

- **WHEN** a user creates a bot without choosing an exchange
- **THEN** the bot is configured for Binance

#### Scenario: Select another supported exchange

- **WHEN** a user selects an exchange supported by the connectivity layer
- **THEN** the bot stores that exchange and uses it for market data and order operations

### Requirement: Market data access

The system SHALL provide current price, order book or ticker data, and trading-rule information for the selected exchange and symbol through a normalized interface. Market data SHALL be retrievable without private credentials.

#### Scenario: Fetch market price

- **WHEN** the engine requests the current price for a bot's symbol and exchange
- **THEN** the connectivity layer returns a normalized price without requiring API credentials

#### Scenario: Trading rules are available

- **WHEN** a bot prepares an order
- **THEN** the system can read the symbol's minimum quantity, price precision, and minimum notional from the exchange

### Requirement: Paper trading is the default

New bots SHALL run in paper mode unless the user explicitly enables live mode. Paper mode SHALL simulate fills using public market data and SHALL NOT send any private or order-placing request to the exchange.

#### Scenario: Default to paper mode

- **WHEN** a bot is created without an explicit mode
- **THEN** the bot runs in paper mode

#### Scenario: Paper mode never places private orders

- **WHEN** a paper-mode bot processes a tick or fill
- **THEN** no authenticated or order-placing request is sent to the exchange

### Requirement: Paper fill simulation

Paper mode SHALL simulate a limit order fill when the market price reaches or crosses the order price. Simulated fills SHALL be recorded and processed through the same order and inventory behavior as live fills.

#### Scenario: Simulate a buy fill

- **WHEN** the market price falls to or below an open paper buy order's price
- **THEN** the order is filled at its limit price and the fill is recorded

#### Scenario: Simulate a sell fill

- **WHEN** the market price rises to or above an open paper sell order's price
- **THEN** the order is filled at its limit price and the fill is recorded

### Requirement: Live order execution

In live mode the system SHALL place and cancel limit orders through the selected exchange and SHALL use the exchange's returned order identifier for later synchronization.

#### Scenario: Place a live order

- **WHEN** a live bot needs to place a limit order and the exchange accepts it
- **THEN** the system records the exchange order identifier and tracks the order as open

#### Scenario: Exchange rejects a live order

- **WHEN** the exchange rejects a live order
- **THEN** the system records the rejection, leaves the level without an open order, and surfaces the error

### Requirement: Credential protection

Exchange credentials SHALL be encrypted at rest, SHALL never be included in logs or rendered back to the user after saving, and SHALL be supplied to the exchange client only at runtime.

#### Scenario: Store credentials

- **WHEN** a user saves exchange credentials
- **THEN** the credentials are stored encrypted and the saved secret is not displayed again

#### Scenario: Keep secrets out of logs

- **WHEN** the system logs an exchange request or error
- **THEN** API keys, secrets, and signatures are redacted

### Requirement: Rate limiting and normalized errors

The connectivity layer SHALL respect exchange rate limits and SHALL translate exchange-specific failures into a common error set covering authentication, rate limit, insufficient balance, invalid order, and unavailable exchange.

#### Scenario: Rate limit reached

- **WHEN** the exchange reports that the rate limit has been exceeded
- **THEN** the system backs off before retrying and records a rate-limit error

#### Scenario: Normalize an exchange failure

- **WHEN** the exchange returns an error that is not one of the common error types
- **THEN** the connectivity layer reports it as an unavailable-exchange error with the original detail retained for diagnostics

### Requirement: Testnet support

The system SHALL allow a live-mode bot to target an exchange testnet or sandbox environment when the exchange supports one.

#### Scenario: Run against testnet

- **WHEN** a bot is configured for live mode with testnet enabled and the exchange supports testnet
- **THEN** order operations are sent to the exchange testnet environment

### Requirement: Failure handling

Repeated exchange failures for a bot SHALL pause that bot, cancel its open orders where possible, and record an error. The system SHALL NOT silently switch a live bot into paper mode.

#### Scenario: Exchange becomes unavailable

- **WHEN** exchange requests for a running bot fail repeatedly beyond the configured threshold
- **THEN** the bot is paused, remaining orders are canceled where possible, and an error is recorded

#### Scenario: No silent mode switch

- **WHEN** a live bot encounters exchange failures
- **THEN** the bot remains configured as live and requires explicit user action to change modes

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
