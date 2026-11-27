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

The system SHALL provide an authenticated preview that persists no bot, no grid, no order, and no risk-limit change, and that returns the evaluated plan, effective limits, warnings, and any market data used. Preview requests SHALL be rate limited together with other bot-control actions. Preview evaluation SHALL NOT make private exchange calls.

#### Scenario: Preview leaves no records

- **WHEN** a user previews a valid setup repeatedly
- **THEN** the bot, grid, order, and risk-limit tables are unchanged

#### Scenario: Preview requires authentication

- **WHEN** an unauthenticated request reaches the preview endpoint
- **THEN** the request is redirected to sign in and no evaluation result is returned

### Requirement: Source-backed settings are derived server-side

When a bot is created from a completed backtest run, the server SHALL derive its exchange, symbol, strategy, capital allocation, fee rate, strategy configuration, and protective exits from the run's immutable snapshot before request validation and engine plan evaluation. Browser-submitted source-derived values SHALL NOT be trusted.

#### Scenario: Snapshot settings are mapped for every grid strategy
- **WHEN** a source-backed bot is created from a simple, trailing, martingale, or dynamic grid run
- **THEN** the bot receives that strategy's center price, level count, spacing, order quantity, and strategy-specific parameters from the snapshot

#### Scenario: Level count falls back to the snapshot key
- **WHEN** the snapshot contains `levels_per_side` but not `level_count`
- **THEN** the mapper uses `levels_per_side` as the level count

#### Scenario: Sweep overrides are preserved
- **WHEN** the source run is a sweep run
- **THEN** the bot receives the run's swept center price, spacing, and level values rather than the original bot values

#### Scenario: Protective exits are copied
- **WHEN** the snapshot contains protective exits
- **THEN** the created bot receives those stop-loss, take-profit, and range settings

#### Scenario: Absent protective exits use defaults
- **WHEN** the snapshot has no protective exit section
- **THEN** the bot uses the normal creation defaults and creation still succeeds

#### Scenario: Dynamic grid zero spacing is handled
- **WHEN** a dynamic-grid snapshot carries a zero or absent spacing value
- **THEN** the mapper does not submit a zero value that fails validation and instead uses a valid omission or positive value

#### Scenario: Tampered source settings are rejected
- **WHEN** a client submits source-derived settings that differ from the run snapshot
- **THEN** validation and creation use the snapshot values and the created bot matches the source

#### Scenario: Preview and store agree
- **WHEN** the source-backed form requests a preview and then submits the same plan
- **THEN** preview and store derive the same settings from the source snapshot

#### Scenario: Source run deleted while the form is open
- **WHEN** the source run is deleted after the form opens but before submission
- **THEN** the request is rejected and no bot is created

### Requirement: Source-backed creation is paper and idle

Source-backed creation SHALL force paper mode, create the bot idle, persist the source link atomically, use the user's current risk limits, and SHALL NOT publish a start command.

#### Scenario: Paper mode is enforced
- **WHEN** a user attempts to create a source-backed bot with live mode
- **THEN** the request is rejected and no bot is created

#### Scenario: Bot is created idle
- **WHEN** a source-backed bot is created successfully
- **THEN** it is in paper mode with idle status and no start command is published

#### Scenario: Risk limits are current
- **WHEN** a source-backed bot is created
- **THEN** its risk limits come from the user's current limits, not from the historical backtest snapshot

#### Scenario: Engine preview unavailable fails closed
- **WHEN** the engine cannot evaluate the source-backed plan
- **THEN** validation fails and no bot is created

#### Scenario: Missing strategy configuration blocks creation
- **WHEN** the source run snapshot has no usable strategy configuration
- **THEN** the request is rejected and no bot is created

#### Scenario: Source link is persisted atomically
- **WHEN** source-backed creation succeeds
- **THEN** the bot, inventory, strategy configuration, protective exits, source link, and `bot.created` activity are committed together

### Requirement: Engine evaluate interface is internal

The engine evaluate interface used for preview and save validation SHALL be reachable only on the internal application network and SHALL NOT be published on the public HTTP surface or published to the host. If the engine HTTP port is published to the host, the evaluate interface SHALL require a shared internal token.

#### Scenario: Preview endpoint is internal

- **WHEN** an unauthenticated client on the public HTTP surface requests the engine evaluate path directly
- **THEN** the path is not served by the public reverse proxy

#### Scenario: Host-published port requires a token

- **WHEN** the engine HTTP port is published to the host and a client calls the evaluate interface without the internal token
- **THEN** the request is rejected and no plan is evaluated
