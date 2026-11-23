# Spec Delta

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

### Requirement: Credential protection

Exchange credentials SHALL be encrypted at rest by Laravel, SHALL never be included in logs, events, or rendered back to the user after saving, and SHALL be supplied to the exchange client only at runtime. The Go engine SHALL decrypt Laravel-encrypted credentials in memory using the process `APP_KEY`, SHALL NOT persist a decrypted copy, and SHALL NOT log `APP_KEY`, API keys, secrets, or signatures.

#### Scenario: Store credentials

- **WHEN** a user saves exchange credentials
- **THEN** the credentials are stored encrypted and the saved secret is not displayed again

#### Scenario: Keep secrets out of logs

- **WHEN** the system logs an exchange request or error
- **THEN** API keys, secrets, and signatures are redacted

#### Scenario: Engine decrypts only in memory

- **WHEN** a live bot needs a private exchange client
- **THEN** the engine decrypts the stored payload in memory and does not write the secret to disk, events, or logs

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
