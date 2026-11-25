## ADDED Requirements

### Requirement: Live grid orders are placed by the engine

For a live grid bot the Go engine SHALL place and cancel the grid's limit orders on the selected exchange, subject to the live gate, risk checks, and client-order-id idempotency defined in exchange-connectivity and risk-controls. Paper grid fills remain simulated and SHALL NOT call a private exchange endpoint.

#### Scenario: Live grid place goes through the engine

- **WHEN** a live grid bot needs to open a level and the live and risk gates allow it
- **THEN** the Go engine places the limit order and records the exchange order identifier on that level

#### Scenario: Paper grid does not use private endpoints

- **WHEN** a paper grid bot opens or fills a level
- **THEN** no private exchange request is sent
