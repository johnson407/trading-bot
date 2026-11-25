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

Tick evaluation SHALL read the cached public last price for the bot's exchange and symbol. The default staleness bound SHALL be 15 seconds, or half of `TRADING_TICK_INTERVAL` when that is larger. When the cache is missing or older than the bound, the engine SHALL take a public REST snapshot and record the stale state without secrets. The engine SHALL NOT use a too-old price when no fresh snapshot is available, and SHALL NOT use private credentials for market data.

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

Every live place SHALL include a stable client order identifier derived from the order intent. The engine SHALL persist that identifier durably before any network place call. A retry or startup resume of the same intent SHALL reuse that identifier, including when no exchange order identifier has been recorded yet, and SHALL NOT mint a new identifier for that intent.

#### Scenario: Identifier is persisted before the network call

- **WHEN** the engine is about to place a live order
- **THEN** the client order identifier is durably stored before any private exchange request is sent

#### Scenario: Startup looks up an in-flight intent

- **WHEN** the engine starts and a live intent has a client order identifier but no exchange order identifier
- **THEN** the engine looks up that identifier on the exchange before placing again and does not mint a new identifier

#### Scenario: Retry reuses the client order identifier

- **WHEN** the engine retries a place for the same order intent after a timeout or uncertain response
- **THEN** it first looks up the order by the existing client order identifier, or follows the venue's duplicate-ack path, then reuses that identifier and does not create a second intent

### Requirement: Lookup before an uncertain live retry

After an uncertain live place, the engine SHALL query the exchange by the existing client order identifier, or follow a documented duplicate-ack path, before sending another place.

#### Scenario: Timeout is looked up before a second place

- **WHEN** a live place times out or returns an unknown outcome
- **THEN** the engine queries the exchange by the existing client order identifier, or follows the duplicate-ack path, and does not send a second place first

### Requirement: Live client order identifier generation

The generation component of a client order identifier SHALL bump only when a new intent is created after the previous order on that key is filled or canceled. It SHALL NOT bump on an in-flight retry. Identifiers SHALL satisfy the selected venue's length and character rules. A completed identifier SHALL NOT be reused for a new intent.

#### Scenario: Generation bumps after fill then re-arm

- **WHEN** a live level is filled or canceled and the engine places a new order for that level
- **THEN** the new intent uses a new generation and a new client order identifier

#### Scenario: Generation does not bump on retry

- **WHEN** a live place is retried while the original intent is still in flight
- **THEN** the generation and client order identifier are unchanged

#### Scenario: Identifier meets venue encoding rules

- **WHEN** the engine assigns a client order identifier for a venue
- **THEN** the identifier satisfies that venue's length and character rules

### Requirement: Testnet-first live proofs

Automated and apply-time proofs of live place, cancel, and reconcile SHALL use a fake exchange or Binance testnet. Default CI and default compose SHALL NOT call a real-money account. An opt-in suite MAY target Binance testnet only and SHALL be skipped by default. The MAX adapter SHALL be proven with fakes only. A real-money account SHALL be used only when the operator explicitly authorizes that exact action.

#### Scenario: Default verify stays off real money

- **WHEN** the default CI or compose test suite runs
- **THEN** no private call is made to a real-money exchange account

#### Scenario: Opt-in testnet suite

- **WHEN** the operator enables the opt-in testnet suite with testnet credentials and sandbox URLs
- **THEN** order operations are sent to Binance testnet and not to production endpoints or to MAX

#### Scenario: MAX is proven on fakes only

- **WHEN** verify or apply proves the MAX adapter
- **THEN** the proofs use a fake exchange and do not call MAX testnet or MAX production

### Requirement: Opt-in testnet suite host guards

The opt-in Binance testnet suite SHALL hard-fail without sending a private request if the resolved base URL is not a known Binance testnet host, if sandbox or credential testnet is not enabled, or if a production key or production URL is configured.

#### Scenario: Suite refuses production configuration

- **WHEN** the opt-in testnet suite is started with a production base URL or a non-testnet credential
- **THEN** it fails without sending a private request

### Requirement: Testnet transport isolation

When testnet or sandbox is enabled, the engine SHALL send private requests only to official testnet base URLs. If a proxy, custom base URL, or NAS relay would rewrite those requests to a production host, the engine SHALL fail closed and SHALL NOT send the request. The opt-in suite SHALL NOT use a relay that cannot preserve testnet URLs.

#### Scenario: Relay rewrite is refused

- **WHEN** testnet is enabled and the configured transport would send the request to a production host
- **THEN** the engine does not send the request and reports an error
