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

### Requirement: Paper trading is the default

New bots SHALL run in paper mode unless the user explicitly enables live mode. Paper mode SHALL simulate fills using public market data and SHALL NOT send any private or order-placing request to the exchange.

#### Scenario: Default to paper mode

- **WHEN** a bot is created without an explicit mode
- **THEN** the bot runs in paper mode

#### Scenario: Paper mode never places private orders

- **WHEN** a paper-mode bot processes a tick or fill
- **THEN** no authenticated or order-placing request is sent to the exchange

### Requirement: Paper fill simulation

Paper mode SHALL simulate a limit order fill when the market price reaches or crosses the order price. Simulated fills SHALL be recorded and processed through the same order and inventory behavior as live fills. Paper fills SHALL be evaluated from the market last observed when a tick runs, matching `PaperExchangeClient::qualifyingFills`. A price that crosses and recrosses between ticks is not guaranteed to fill.

#### Scenario: Simulate a buy fill

- **WHEN** the market price falls to or below an open paper buy order's price
- **THEN** the order is filled at its limit price and the fill is recorded

#### Scenario: Simulate a sell fill

- **WHEN** the market price rises to or above an open paper sell order's price
- **THEN** the order is filled at its limit price and the fill is recorded

#### Scenario: Cross and recross between ticks

- **WHEN** the market price crosses an open paper order's limit and returns before the next tick runs
- **THEN** no fill is guaranteed, and the system does not claim the order was filled

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

### Requirement: Credential protection

Exchange credentials SHALL be encrypted at rest by Laravel using the Eloquent `encrypted` cast (`Crypt::encryptString`: AES-256-CBC, HMAC-SHA256, JSON `iv`, `value`, `mac`, `tag`, base64; values are not PHP-serialized). The engine SHALL decrypt in memory with `APP_KEY` (optional `base64:` prefix) and `previous_keys` as Laravel does, SHALL NOT persist a decrypted copy, and SHALL NOT log `APP_KEY`, API keys, secrets, or signatures.

#### Scenario: Store credentials

- **WHEN** a user saves exchange credentials
- **THEN** the credentials are stored encrypted and the saved secret is not displayed again

#### Scenario: Keep secrets out of logs

- **WHEN** the system logs an exchange request or error
- **THEN** API keys, secrets, and signatures are redacted

#### Scenario: Engine decrypts only in memory

- **WHEN** a live bot needs a private exchange client
- **THEN** the engine decrypts the stored payload in memory and does not write the secret to disk, events, or logs

#### Scenario: Decrypt matches Laravel encryptString

- **WHEN** the engine decrypts a stub value produced by Laravel `Crypt::encryptString`
- **THEN** it recovers the original plaintext and does not require PHP serialized payload wrapping

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

### Requirement: Historical market data access

The engine SHALL retrieve historical OHLCV candles for a supported symbol, timeframe, and time range through public exchange endpoints without credentials. Retrieval SHALL honor exchange rate limits, SHALL normalize exchange failures into the existing error set, and SHALL return candles in ascending open time.

#### Scenario: Fetch candles without credentials

- **WHEN** the system requests historical candles for a symbol and timeframe
- **THEN** the exchange returns public data without an API key or secret

#### Scenario: Range exceeds one request

- **WHEN** the requested range contains more candles than a single exchange request allows
- **THEN** the system retrieves the range in successive requests and returns the combined candles in ascending order

#### Scenario: Exchange failure is normalized

- **WHEN** the exchange rejects or fails a historical data request
- **THEN** the failure is reported as a normalized connectivity error with the exchange name and operation

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
