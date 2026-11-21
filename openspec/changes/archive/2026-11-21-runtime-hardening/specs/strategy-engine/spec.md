# Spec Delta

## MODIFIED Requirements

### Requirement: Strategy lifecycle contract

Every strategy SHALL support initialization from its configuration, evaluation on each price tick, notification of fills and cancellations, a stop decision, and generation of grid levels. The engine SHALL invoke these behaviors consistently. Cancellations SHALL be reported through `onCancel`, a stop decision SHALL use the standard stop procedure, and missing persisted state after a completed tick SHALL pause the bot.

#### Scenario: Evaluate a price tick

- **WHEN** a running bot receives a price tick
- **THEN** the engine passes the tick to the selected strategy and applies the resulting decisions

#### Scenario: Strategy requests a stop

- **WHEN** a strategy reports that its stop condition is met
- **THEN** the bot stops placing new orders and follows the standard stop procedure for open orders

#### Scenario: Cancellation callback

- **WHEN** the engine cancels an order for a strategy or replaces the grid
- **THEN** the strategy receives `onCancel` for that client order id

#### Scenario: Missing state after a completed tick

- **WHEN** a bot has a completed tick but its persisted strategy state is missing
- **THEN** the bot pauses and the missing state is reported instead of reinitializing

### Requirement: Trailing grid strategy

The Trailing strategy SHALL keep the grid centered near the current market price and move the center only after price moves beyond a configured trailing distance. When the center moves, orders outside the new grid SHALL be canceled and levels inside the new grid SHALL be replenished.

#### Scenario: Price moves beyond trailing distance

- **WHEN** market price moves from the current center by at least the configured trailing distance
- **THEN** the grid center moves to the new price and the level set is recalculated

#### Scenario: Price stays within trailing distance

- **WHEN** market price moves by less than the configured trailing distance
- **THEN** the grid center and its open orders remain unchanged

#### Scenario: Replenish the recreated grid

- **WHEN** the grid is recreated after a trailing recenter
- **THEN** every level in the new grid receives an order for its current side

### Requirement: Dynamic grid strategy

The Dynamic strategy SHALL adjust grid spacing from recent market volatility and SHALL clamp the result to configured minimum and maximum spacing. Spacing SHALL be recalculated no more often than the configured recalculation interval. The engine SHALL supply the strategy with a rolling window of recent prices.

#### Scenario: Volatility widens spacing

- **WHEN** recent volatility rises above its previous measurement
- **THEN** the recalculation produces wider spacing, within the configured bounds

#### Scenario: Spacing is clamped

- **WHEN** the calculated spacing falls outside the configured minimum or maximum
- **THEN** the spacing is set to the nearest configured bound

#### Scenario: Respect the recalculation interval

- **WHEN** the recalculation interval has not elapsed since the previous adjustment
- **THEN** the current spacing is retained

#### Scenario: Recent prices available

- **WHEN** a running bot has completed at least two ticks
- **THEN** the strategy receives those prices in `MarketSnapshot::recentPrices`
