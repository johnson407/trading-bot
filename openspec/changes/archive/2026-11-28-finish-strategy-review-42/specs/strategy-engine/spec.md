## MODIFIED Requirements

### Requirement: Trailing grid strategy

The Trailing strategy SHALL keep the grid centered near the current market price and move the center only after price moves beyond a configured trailing distance. When the center moves, orders outside the new grid SHALL be canceled and levels inside the new grid SHALL be replenished. On a recenter, a disarmed entry whose recorded paired price is absent from the new ladder SHALL be pruned so it can no longer block a level at the same price; entries with no recorded pair SHALL remain disarmed.

#### Scenario: Price moves beyond trailing distance

- **WHEN** market price moves from the current center by at least the configured trailing distance
- **THEN** the grid center moves to the new price and the level set is recalculated

#### Scenario: Price stays within trailing distance

- **WHEN** market price moves by less than the configured trailing distance
- **THEN** the grid center and its open orders remain unchanged

#### Scenario: Replenish the recreated grid

- **WHEN** the grid is recreated after a trailing recenter
- **THEN** every level in the new grid receives an order for its current side

#### Scenario: Stale disarmed level is pruned

- **WHEN** a recentered ladder no longer contains the price paired with a disarmed entry
- **THEN** that disarm entry is removed and the level can receive an order when its price appears again

### Requirement: Dynamic grid strategy

The Dynamic strategy SHALL compute candidate spacing from recent volatility, clamp it to the configured minimum and maximum, and recalculate no more often than the configured interval. A due recalculation SHALL adopt the candidate only when it differs from current spacing by at least the hysteresis percentage (default 20, range 0-90); zero disables hysteresis. With fewer than two prices, spacing SHALL use `initial_spacing` when present, otherwise `min_spacing`, clamped to the configured bounds.

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

#### Scenario: Initial spacing is clamped

- **WHEN** a new dynamic grid has fewer than two recent prices and `initial_spacing` is outside the configured bounds
- **THEN** the first ladder uses the nearest bound

#### Scenario: Missing initial spacing falls back

- **WHEN** a new dynamic grid has fewer than two recent prices and no `initial_spacing`
- **THEN** the first ladder uses `min_spacing`

#### Scenario: Stale dynamic disarmed level is pruned

- **WHEN** a due recalculation adopts a ladder that no longer contains a disarmed entry's paired price
- **THEN** that disarm entry is removed before orders are emitted
