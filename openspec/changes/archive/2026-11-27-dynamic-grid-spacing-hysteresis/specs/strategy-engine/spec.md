# Spec Delta

## MODIFIED Requirements

### Requirement: Dynamic grid strategy

The Dynamic strategy SHALL compute a candidate spacing from recent volatility, clamp it to the configured minimum and maximum, and recalculate no more often than the configured interval. A due recalculation SHALL adopt the candidate only when it differs from the current spacing by at least the hysteresis percentage (default 20, range 0-90), relative to the current spacing; zero disables hysteresis. The engine SHALL supply a rolling window of recent prices.

#### Scenario: Volatility widens spacing

- **WHEN** recent volatility rises above its previous measurement and the candidate differs from the current spacing by at least the configured hysteresis percentage
- **THEN** the due recalculation adopts the wider candidate spacing, within the configured bounds

#### Scenario: Spacing is clamped

- **WHEN** the calculated candidate falls outside the configured minimum or maximum
- **THEN** the candidate is clamped to the nearest configured bound before the hysteresis comparison, and the current spacing is retained when the relative difference is below the threshold

#### Scenario: Respect the recalculation interval

- **WHEN** the recalculation interval has not elapsed since the previous adjustment
- **THEN** the current spacing is retained

#### Scenario: Recent prices available

- **WHEN** a running bot has completed at least two ticks
- **THEN** the strategy receives those prices in `MarketSnapshot::recentPrices`

#### Scenario: Small spacing change retains the ladder

- **WHEN** a due recalculation produces spacing within the configured hysteresis percentage of the current spacing
- **THEN** the current spacing and grid levels are retained, and the recalculation timestamp advances

#### Scenario: Change at the threshold adopts new spacing

- **WHEN** the candidate spacing differs from the current spacing by exactly the configured hysteresis percentage
- **THEN** the candidate spacing is adopted and the levels are recalculated

#### Scenario: Zero hysteresis adopts every candidate

- **WHEN** hysteresis is configured as zero
- **THEN** every due recalculation adopts the candidate spacing

#### Scenario: Default hysteresis

- **WHEN** a dynamic bot does not configure a hysteresis percentage
- **THEN** the strategy uses 20 percent

#### Scenario: Invalid hysteresis is rejected

- **WHEN** a dynamic bot is saved with a hysteresis percentage that is negative, greater than 90, or not numeric
- **THEN** validation fails, identifies the hysteresis field, and no bot is saved

## ADDED Requirements

### Requirement: Dynamic grid state validation

The engine SHALL require the persisted dynamic-grid spacing to be a positive numeric decimal before a due recalculation. Missing, non-numeric, zero, or negative spacing SHALL fail the tick under the existing state-persistence rules, and the strategy SHALL NOT adopt a candidate as recovery.

#### Scenario: Invalid current spacing fails closed

- **WHEN** the persisted spacing is missing, non-numeric, zero, or negative during a due recalculation
- **THEN** the tick fails under the existing state-persistence rules and no new spacing is adopted
