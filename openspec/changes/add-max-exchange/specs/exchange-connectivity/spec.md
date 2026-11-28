# Spec Delta

## MODIFIED Requirements

### Requirement: Testnet-first live proofs

Automated and apply-time proofs of live place, cancel, and reconcile SHALL use a fake exchange or Binance testnet. Default CI and default compose SHALL NOT call a real-money account. An opt-in suite MAY target Binance testnet only and SHALL be skipped by default. MAX has no testnet; the MAX adapter SHALL be proven in automated tests with fakes and recorded fixtures only. A real-money account SHALL be used only when the operator explicitly authorizes that exact action.

#### Scenario: Default verify stays off real money

- **WHEN** the default CI or compose test suite runs
- **THEN** no private call is made to a real-money exchange account

#### Scenario: Opt-in testnet suite

- **WHEN** the operator enables the opt-in testnet suite with testnet credentials and sandbox URLs
- **THEN** order operations are sent to Binance testnet and not to production endpoints or to MAX

#### Scenario: MAX is proven on fakes only

- **WHEN** verify or apply proves the MAX adapter
- **THEN** the proofs use a fake exchange or recorded fixtures and do not call MAX testnet or send a private request to MAX production

## ADDED Requirements

### Requirement: MAX public market data

The engine SHALL read MAX last prices from the public MAX WebSocket trade channel, falling back to the public MAX REST ticker when the cached price is missing or older than the staleness bound. Market-data sources SHALL be registered per exchange, and a price request for an exchange with no registered source SHALL fail closed. MAX market data SHALL NOT use credentials, SHALL keep within MAX's documented request and connection limits, and SHALL honour a `Retry-After` response before reconnecting.

#### Scenario: Paper tick uses the MAX cached price

- **WHEN** a running paper bot on `max` is evaluated and a cached public MAX price for its symbol is within the staleness bound
- **THEN** the engine uses that price and sends no private request

#### Scenario: Stale MAX cache falls back to REST

- **WHEN** the cached MAX price is missing or older than the staleness bound
- **THEN** the engine takes a public MAX REST ticker snapshot and uses it only if it is fresh

#### Scenario: Unregistered exchange fails closed

- **WHEN** a price is requested for an exchange that has no registered market-data source
- **THEN** the request fails with an error and no price from another exchange is substituted

#### Scenario: MAX rate-limits the connection

- **WHEN** MAX answers a connection attempt with HTTP 429 and a `Retry-After` value
- **THEN** the feed does not reconnect before that time

### Requirement: MAX symbol rules

For MAX markets the engine SHALL derive price precision, amount precision, minimum amount, and minimum notional from the public MAX markets list, SHALL support negative precision values, and SHALL map between bot symbols such as `BTC/TWD` and MAX market ids such as `btctwd` using the base and quote units in that list. Quantities and prices SHALL be rounded to those rules before a client order identifier is derived. The engine SHALL NOT place a new order on a market whose status is not `active`.

#### Scenario: Round to MAX precision

- **WHEN** a MAX order price or quantity has more decimal places than the market allows
- **THEN** it is rounded down to the market's precision before the order is built

#### Scenario: Below minimum is not sent

- **WHEN** an order's quantity is below the market's minimum base amount or its notional is below the minimum quote amount
- **THEN** the order is not sent and the existing `order.min_amount` or `order.min_notional` risk event is recorded

#### Scenario: Market is not active

- **WHEN** a MAX market status is `cancel-only` or `suspended`
- **THEN** the engine does not place new orders on it and records the reason without secrets

### Requirement: MAX request signing

The MAX client SHALL follow the official MAX API v3 authentication: headers `X-MAX-ACCESSKEY`, `X-MAX-PAYLOAD`, and `X-MAX-SIGNATURE`, where the payload is the base64 JSON of the request parameters plus a never-repeated millisecond `nonce` and the request `path`, and the signature is the hex HMAC-SHA256 of that payload string keyed with the secret.

#### Scenario: Signed request matches the documented algorithm

- **WHEN** the client builds a signed request for fixed inputs taken from the MAX documentation
- **THEN** the headers, payload, and signature equal the documented values

#### Scenario: Nonce is never reused

- **WHEN** two requests are built for the same credential within the same millisecond
- **THEN** their nonces differ and increase

### Requirement: MAX order semantics

The MAX client SHALL use the v3 spot wallet and order endpoints, SHALL treat order creation and cancellation as asynchronous and confirm them by order lookup, SHALL map order states with partial fills preserved, SHALL parse the v3 error body into the existing normalized errors, and SHALL take fees and fee currency from the trade records rather than assuming a currency.

#### Scenario: Partial fill is preserved

- **WHEN** MAX returns an order with state `wait` or `cancel` and a non-zero executed volume
- **THEN** the order is reported with that executed quantity and is not reported as plain open or plain cancelled

#### Scenario: Normalize a MAX error

- **WHEN** MAX returns HTTP 429, 401, or an error body with a code and message
- **THEN** the failure is reported as a normalized rate-limit, authentication, or rejection error, with the `Retry-After` value kept for rate limits, and no key, signature, or payload appears in the message

#### Scenario: Create timed out

- **WHEN** a MAX order creation times out without a response
- **THEN** the engine looks the order up by client order identifier before sending another create

#### Scenario: Client order identifier already used

- **WHEN** MAX rejects a create because the client order identifier was used in the last 24 hours
- **THEN** the engine resolves the intent by looking up that identifier and does not place a second order

### Requirement: MAX read-only verification and dry run

Because MAX has no testnet, the engine SHALL provide a read-only verification command and a dry-run mode for MAX. The command SHALL use only `GET` requests, SHALL print no secret, and SHALL refuse to build a `POST` or `DELETE` request. Dry-run SHALL build, round, validate, and sign the order the live path would send, record the intent with a redacted log, and SHALL NOT send the request.

#### Scenario: Read-only verification

- **WHEN** the operator runs the MAX verification command for a credential
- **THEN** it reports clock offset, permissions, balances, and open order count using only `GET` requests and prints no key, secret, payload, or signature

#### Scenario: Dry-run sends nothing

- **WHEN** a live MAX bot runs with dry-run enabled
- **THEN** order intents are recorded and logged in redacted form and no order request is sent to MAX

### Requirement: First real MAX order

The first real MAX order for a credential SHALL be a single minimum-size limit order that the operator has explicitly authorized by bot, market, size, and price.

#### Scenario: First order needs explicit approval

- **WHEN** the first real MAX order for a credential would be sent
- **THEN** it is sent only after the operator has explicitly authorized that bot, market, size, and price

### Requirement: MAX transport

MAX requests SHALL use a MAX-specific base URL that defaults to the official MAX host and SHALL NOT inherit the Binance base-URL override. TLS verification SHALL remain enabled for any relay.

#### Scenario: Binance relay does not affect MAX

- **WHEN** a custom exchange base URL is configured for Binance
- **THEN** MAX requests still go to the MAX base URL

### Requirement: MAX credentials

A MAX credential SHALL consist of an access key and secret only, SHALL be stored encrypted and masked like other credentials, and SHALL NOT be accepted with the testnet flag. The documentation SHALL state that the API key must have withdrawals disabled and be restricted to the egress IP of the host that runs the engine.

#### Scenario: Reject a MAX testnet credential

- **WHEN** a MAX credential is saved or loaded with the testnet flag set
- **THEN** the engine refuses to build a MAX client for it and no private request is sent

#### Scenario: Secrets stay out of logs

- **WHEN** a MAX request fails or is logged
- **THEN** the access key, secret, signature, and payload are absent from logs, errors, and risk events
