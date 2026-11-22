# Spec Delta

## Purpose

Defines a consistent presentation rule for monetary amounts so users see readable two-decimal values throughout the dashboard, bot, risk, and backtest surfaces.

## ADDED Requirements

### Requirement: Monetary amounts use two decimal places

Every monetary amount presented to a user SHALL be rendered with exactly two fractional digits, rounded half up, without scientific notation or trailing zeroes beyond the second decimal. Monetary amounts include prices, capital allocation, inventory values, profit and loss, fees, equity, position notional, and risk limits denominated in the quote currency.

#### Scenario: Render a whole amount

- **WHEN** a stored amount is `500.00000000`
- **THEN** the interface shows `500.00`

#### Scenario: Render a fractional amount

- **WHEN** a stored amount is `500.126`
- **THEN** the interface shows `500.13`

#### Scenario: Render a negative amount

- **WHEN** a stored amount is `-25.5`
- **THEN** the interface shows `-25.50`

#### Scenario: Render zero

- **WHEN** a stored amount is zero
- **THEN** the interface shows `0.00`

### Requirement: Editable monetary fields are prefilled at display precision

A form field that edits a monetary amount SHALL be prefilled with the same two-decimal presentation used in read-only views. Saving an unchanged value SHALL preserve the stored amount.

#### Scenario: Open a bot edit form

- **WHEN** a bot has a stored capital allocation of `1000.0000000000000000`
- **THEN** the capital allocation field shows `1000.00`

#### Scenario: Open the risk-limit form

- **WHEN** an account risk limit is stored as `2500.0000000000000000`
- **THEN** the maximum position notional field shows `2500.00`

#### Scenario: Save without editing

- **WHEN** a user saves a prefilled monetary field without changing it
- **THEN** the stored amount is unchanged

### Requirement: Non-monetary values keep their meaningful precision

Quantities, fee rates, percentages, timestamps, and raw configuration snapshots SHALL NOT be forced to two decimal places. They SHALL continue to display the precision needed to distinguish their values.

#### Scenario: Show a base-asset quantity

- **WHEN** an order quantity is `0.001`
- **THEN** the interface shows a non-zero quantity rather than rounding it to `0.00`

#### Scenario: Show a fee rate

- **WHEN** a fee rate is `0.001`
- **THEN** the interface keeps enough precision to distinguish it from zero

#### Scenario: Show a percentage

- **WHEN** a stop-loss percentage is `2.5`
- **THEN** the interface shows `2.50%` or the existing percentage precision, not a currency-formatted value

#### Scenario: Show geometric grid spacing

- **WHEN** a swept geometric spacing is the fraction `0.002`
- **THEN** the interface keeps enough precision to distinguish it from `0.005` and does not round it to a currency value such as `0.00`

### Requirement: Formatting is consistent across monetary surfaces

The dashboard, bot list, bot detail, bot create and edit forms, risk-limit form, backtest list, backtest detail, backtest comparison, and sweep detail SHALL apply the same monetary presentation rule to every monetary value they show or prefill.

#### Scenario: Compare the same amount on two pages

- **WHEN** the same stored amount appears on a list page and a detail page
- **THEN** both pages show the same two-decimal representation

#### Scenario: Display a monetary value with sign

- **WHEN** a page shows a positive or negative profit-and-loss amount
- **THEN** the amount uses two decimals and retains its sign independent of any color styling
