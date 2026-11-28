## MODIFIED Requirements

### Requirement: Setup plan evaluation

The system SHALL evaluate a bot setup without persisting it and SHALL return the generated grid levels, each level's side, price, quantity, and notional, plus order count, buy notional, and sell notional. The same Go-engine evaluation SHALL serve preview, creation, and update. Before reporting a setup as valid, evaluation SHALL truncate prices and quantities to the market's precision and reject values that round to zero or fall below a minimum.

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

#### Scenario: Rounded order is too small

- **WHEN** a level price or order quantity truncates to zero at the market's precision
- **THEN** evaluation rejects the setup and identifies the affected field

#### Scenario: Rounded order misses a minimum

- **WHEN** a level price or order quantity after truncation falls below the minimum amount or minimum notional
- **THEN** evaluation rejects the setup and identifies the affected field

## ADDED Requirements

### Requirement: Dynamic initial spacing field

The dynamic-grid setup form SHALL expose an optional positive `initial_spacing`. Preview, create, update, and backtest setup SHALL carry the value to the strategy, and the engine SHALL clamp it to the configured minimum and maximum spacing. When omitted, the strategy SHALL use `min_spacing`.

#### Scenario: Initial spacing is submitted

- **WHEN** a dynamic-grid setup supplies `initial_spacing`
- **THEN** preview, create, update, and backtest pass it through and the first ladder uses the clamped value

#### Scenario: Initial spacing is omitted

- **WHEN** a dynamic-grid setup omits `initial_spacing`
- **THEN** the first ladder uses `min_spacing`

#### Scenario: Invalid initial spacing is rejected

- **WHEN** `initial_spacing` is zero, negative, or non-numeric
- **THEN** validation fails on the `initial_spacing` field and no bot is saved
