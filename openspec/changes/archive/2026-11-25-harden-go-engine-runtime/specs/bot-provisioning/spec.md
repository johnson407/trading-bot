## MODIFIED Requirements

### Requirement: Setup plan evaluation

The system SHALL evaluate a bot setup without persisting it and SHALL return the generated grid levels, each level's side, price, quantity, and notional, plus totals for order count, buy notional, and sell notional. The same evaluation SHALL be used by the preview and by bot creation and update validation so the three cannot disagree. Evaluation SHALL be performed by the Go engine using the same strategy implementations as runtime ticks.

#### Scenario: Evaluate a valid setup

- **WHEN** a valid setup is evaluated
- **THEN** the response lists one buy level per step below the center and one sell level per step above it, with totals that match the level list

#### Scenario: Preview and save agree

- **WHEN** a setup is previewed and then submitted unchanged
- **THEN** the saved bot's grid matches the previewed levels and totals

#### Scenario: Engine performs the evaluation

- **WHEN** a user previews or saves a setup
- **THEN** the Go engine evaluates the plan and Laravel does not invoke a PHP strategy class

#### Scenario: Engine unavailable fails closed

- **WHEN** the engine evaluate interface is unreachable
- **THEN** preview and save both fail with a clear error and no bot is persisted

### Requirement: Preview without persistence

The system SHALL provide an authenticated preview that persists no bot, no grid, no order, and no risk-limit change, and that returns the evaluated plan, effective limits, warnings, and any market data used. Preview requests SHALL be rate limited together with other bot-control actions. Preview evaluation SHALL NOT make private exchange calls.

#### Scenario: Preview leaves no records

- **WHEN** a user previews a valid setup repeatedly
- **THEN** the bot, grid, order, and risk-limit tables are unchanged

#### Scenario: Preview requires authentication

- **WHEN** an unauthenticated request reaches the preview endpoint
- **THEN** the request is redirected to sign in and no evaluation result is returned

## ADDED Requirements

### Requirement: Engine evaluate interface is internal

The engine evaluate interface used for preview and save validation SHALL be reachable only on the internal application network and SHALL NOT be published on the public HTTP surface or published to the host. If the engine HTTP port is published to the host, the evaluate interface SHALL require a shared internal token.

#### Scenario: Preview endpoint is internal

- **WHEN** an unauthenticated client on the public HTTP surface requests the engine evaluate path directly
- **THEN** the path is not served by the public reverse proxy

#### Scenario: Host-published port requires a token

- **WHEN** the engine HTTP port is published to the host and a client calls the evaluate interface without the internal token
- **THEN** the request is rejected and no plan is evaluated
