# bot-provisioning Specification

## Purpose

Lets a user configure a runnable trading bot from a small set of understandable choices by deriving grid mechanics from safe presets, previewing the resulting ladder before saving, and separating configurations that can never work from configurations that merely exceed a soft limit.

## Requirements

### Requirement: Preset catalog

The system SHALL expose a backend-owned catalog of named setup presets. Each preset SHALL define a strategy, a level count, a percentage spacing, a capital utilization factor, and any strategy-specific defaults, and SHALL NOT depend on the traded symbol or a hardcoded absolute price. Every preset SHALL produce a buy-side notional and an open-order count within the default risk limits.

#### Scenario: Preset is symbol-agnostic

- **WHEN** a user applies the same preset to `BTC/USDT` and to `ETH/USDT` with the same capital allocation
- **THEN** the preset derives spacing and order quantity from the submitted symbol and center price rather than from fixed absolute amounts

#### Scenario: Preset fits default limits

- **WHEN** a preset is applied with the default risk limits and a capital allocation of `1000`
- **THEN** the resulting configuration has a buy-side notional and an open-order count within those defaults

### Requirement: Quick-setup derivation

Given a preset, a capital allocation, a center price, a level count, and a percentage spacing, the system SHALL derive the order quantity and the strategy configuration using decimal arithmetic only. The derived quantity SHALL be the allocated capital multiplied by the preset utilization factor, divided by the level count and the center price.

#### Scenario: Derive quantity from capital

- **WHEN** a user supplies capital `1000`, `6` levels per side, and a center price of `50000` with a utilization factor of `0.8`
- **THEN** the derived order quantity is that of `800 / (6 x 50000)` and no floating-point value is used

#### Scenario: Percentage spacing maps to geometric spacing

- **WHEN** a user selects a preset with percentage spacing and turns on advanced fields
- **THEN** the advanced fields show geometric spacing with that percentage as a fraction, and the same values are submitted

### Requirement: Setup plan evaluation

The system SHALL evaluate a bot setup without persisting it and SHALL return the generated grid levels, each level's side, price, quantity, and notional, plus totals for order count, buy notional, and sell notional. The same evaluation SHALL be used by the preview and by bot creation and update validation so the three cannot disagree.

#### Scenario: Evaluate a valid setup

- **WHEN** a valid setup is evaluated
- **THEN** the response lists one buy level per step below the center and one sell level per step above it, with totals that match the level list

#### Scenario: Preview and save agree

- **WHEN** a setup is previewed and then submitted unchanged
- **THEN** the saved bot's grid matches the previewed levels and totals

### Requirement: Preflight rejections

The system SHALL reject, before a bot is saved, a setup that can never place a valid order: geometric spacing greater than or equal to `1`, a generated level price that is zero or negative, duplicate level prices, or, when market data is available, an order quantity below the exchange minimum amount or an order notional below the exchange minimum notional. Rejections SHALL identify the offending field, and no bot SHALL be created.

#### Scenario: Reject geometric spacing of one or more

- **WHEN** a user submits geometric spacing of `1` or greater
- **THEN** creation fails with a spacing error and no bot is created

#### Scenario: Reject a level below the exchange minimum

- **WHEN** market data is available and the configured order quantity is below the exchange minimum amount
- **THEN** creation fails with a quantity error and no bot is created

#### Scenario: Missing market data is not a rejection

- **WHEN** market data cannot be fetched and the configuration is otherwise valid
- **THEN** creation proceeds without the exchange-minimum check

### Requirement: Advisory warnings

The system SHALL return non-blocking warnings for setups that are valid but unlikely to behave as intended, including when the order count exceeds the effective maximum open orders, when buy-side notional exceeds the effective maximum position notional, when the center price differs from the latest market price beyond a preset band, and when sell orders cannot be funded because the bot has no base inventory. Warnings SHALL NOT prevent creation.

#### Scenario: Exceeding a risk limit warns

- **WHEN** a setup's buy notional exceeds the user's effective maximum position notional
- **THEN** the setup shows a warning naming the limit and no error blocks creation

#### Scenario: Warn about unfunded sells

- **WHEN** a new bot starts with zero base inventory
- **THEN** the setup warns that sell levels remain deferred until buys fill

#### Scenario: Warning does not block save

- **WHEN** a user submits a setup that produced only warnings
- **THEN** the bot is created and the warnings are shown on the setup page

### Requirement: Effective limits for preflight

Preflight evaluation SHALL use the same effective risk limits as execution, resolving bot scope, then user scope, then configuration defaults, and SHALL read them without creating or modifying any risk-limit record.

#### Scenario: Read limits without mutation

- **WHEN** a user previews a setup before ever creating a bot
- **THEN** the effective limits are resolved from defaults and user scope and no risk-limit row is written

#### Scenario: User limits are reflected

- **WHEN** a user has raised `max_position_notional` on the Risk Limits page
- **THEN** preflight warnings use the raised value rather than the default

### Requirement: Market data anchoring

The system SHALL let a user request the latest market price, price precision, amount precision, minimum amount, and minimum notional for the selected exchange and symbol, and SHALL apply that price as the center price when requested. When the request fails, the setup flow SHALL remain usable with manually entered values and SHALL report that market data is unavailable.

#### Scenario: Anchor to the current price

- **WHEN** a user requests the current market price and the exchange returns one
- **THEN** the center price is set to that price and the preview is recalculated

#### Scenario: Exchange unavailable

- **WHEN** the market data request fails
- **THEN** the form keeps the manually entered center price, shows that market data is unavailable, and remains submittable

### Requirement: Preview without persistence

The system SHALL provide an authenticated preview that persists no bot, no grid, no order, and no risk-limit change, and that returns the evaluated plan, effective limits, warnings, and any market data used. Preview requests SHALL be rate limited together with other bot-control actions.

#### Scenario: Preview leaves no records

- **WHEN** a user previews a valid setup repeatedly
- **THEN** the bot, grid, order, and risk-limit tables are unchanged

#### Scenario: Preview requires authentication

- **WHEN** an unauthenticated request reaches the preview endpoint
- **THEN** the request is redirected to sign in and no evaluation result is returned
