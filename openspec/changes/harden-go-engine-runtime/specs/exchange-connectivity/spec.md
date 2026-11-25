## MODIFIED Requirements

### Requirement: Market data access

The engine SHALL provide current price, ticker or book-ticker data, and trading-rule information for the selected exchange and symbol through a normalized interface. Public market data SHALL be retrievable without private credentials. For Binance trading data the engine SHALL use a WebSocket feed with reconnect, gap recovery via a REST snapshot, and REST fallback when the socket cannot be established, recorded without secrets.

#### Scenario: Fetch market price

- **WHEN** the engine requests the current price for a bot's symbol and exchange
- **THEN** the connectivity layer returns a normalized price without requiring API credentials

#### Scenario: Trading rules are available

- **WHEN** a bot prepares an order
- **THEN** the system can read the symbol's minimum quantity, price precision, and minimum notional from the exchange

#### Scenario: WebSocket reconnects

- **WHEN** the Binance market WebSocket disconnects while bots are running
- **THEN** the engine reconnects with backoff and resumes a normalized price feed

#### Scenario: Gap is recovered from REST

- **WHEN** the engine detects a gap or unexpected quiet period on the WebSocket
- **THEN** it takes a REST ticker snapshot, resubscribes, and continues without requiring private credentials

#### Scenario: REST fallback when the socket cannot connect

- **WHEN** the WebSocket cannot be established
- **THEN** the engine uses periodic REST tickers and records the degraded state without secrets

### Requirement: Live order execution

In live mode the Go engine SHALL place and cancel limit orders through the selected allowlisted exchange and SHALL use the exchange's returned order identifier for later synchronization.

#### Scenario: Place a live order

- **WHEN** a live bot needs to place a limit order, the live gate is open, risk gates allow it, and the exchange accepts it
- **THEN** the engine records the client order identifier and the exchange order identifier and tracks the order as open

#### Scenario: Exchange rejects a live order

- **WHEN** the exchange rejects a live order
- **THEN** the system records the rejection, leaves the level without an open order, and surfaces the error

#### Scenario: Cancel a live order

- **WHEN** the engine cancels an open live order and the exchange accepts the cancel
- **THEN** the local order is marked canceled and is no longer tracked as open

## ADDED Requirements

### Requirement: Dynamic market-data subscriptions

The market-data feed's subscribed symbols SHALL be the union of symbols used by currently running or starting bots. The engine SHALL unsubscribe a symbol that no running bot still needs.

#### Scenario: Subscribe to running bot symbols

- **WHEN** a bot starts or the engine starts with running bots
- **THEN** the public market-data feed subscribes to those bots' symbols and does not require a static symbol allowlist to begin streaming

#### Scenario: Unsubscribe idle symbols

- **WHEN** the last running bot for a symbol stops
- **THEN** the feed unsubscribes that symbol

### Requirement: Tick last-price cache

Tick evaluation SHALL read the cached public last price for the bot's exchange and symbol. When the cache is missing or older than the configured staleness bound, the engine SHALL take a public REST snapshot and record the degraded or stale state without secrets. The engine SHALL NOT use a price older than the staleness bound when no fresh snapshot is available, and SHALL NOT use private credentials for market data.

#### Scenario: Tick uses the cached last price

- **WHEN** a running bot is evaluated and a cached public price for its exchange and symbol is within the staleness bound
- **THEN** the engine uses that cached price and does not fetch a new REST ticker for that tick

#### Scenario: Stale cache falls back to REST

- **WHEN** the cached price is missing or older than the staleness bound
- **THEN** the engine takes a public REST snapshot, records the stale or degraded state without secrets, and uses the snapshot if it is fresh

#### Scenario: Too-old price is not used

- **WHEN** no cached or REST price is within the staleness bound
- **THEN** the engine does not evaluate orders against that price and records the failure without secrets

### Requirement: Live client order identifiers

Every live place SHALL include a stable client order identifier derived from the order intent. A retry of the same intent SHALL reuse that identifier. The engine SHALL persist the identifier with the intent.

#### Scenario: Retry reuses the client order identifier

- **WHEN** the engine retries a place for the same order intent after a timeout or uncertain response
- **THEN** it sends the same client order identifier and does not create a second intent

### Requirement: Testnet-first live proofs

Automated and apply-time proofs of live place, cancel, and reconcile SHALL use a fake exchange or an official exchange testnet. Default CI and default compose SHALL NOT call a real-money account. An opt-in testnet suite MAY be provided and SHALL be skipped by default. A real-money live account SHALL be used only when the operator explicitly authorizes that exact action.

#### Scenario: Default verify stays off real money

- **WHEN** the default CI or compose test suite runs
- **THEN** no private call is made to a real-money exchange account

#### Scenario: Opt-in testnet suite

- **WHEN** the operator enables the opt-in testnet suite with testnet credentials and sandbox URLs
- **THEN** order operations are sent to the exchange testnet and not to production endpoints
