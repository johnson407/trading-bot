# Spec Delta

## Purpose

Defines how a bot selects and runs a trading strategy, what behavior each supported grid strategy provides, and how strategy state survives restarts.

## ADDED Requirements

### Requirement: Strategy selection

A bot SHALL run any strategy registered under a stable identifier. Creating or updating a bot SHALL select the strategy by identifier without requiring changes to the execution engine. An unregistered identifier SHALL be rejected before the bot is saved.

#### Scenario: Select a registered strategy

- **WHEN** a user creates a bot with the identifier of a registered strategy
- **THEN** the bot stores that identifier and the engine resolves it when the bot runs

#### Scenario: Reject an unknown strategy

- **WHEN** a bot is submitted with an identifier that is not registered
- **THEN** validation fails and no bot is created

### Requirement: Strategy lifecycle contract

Every strategy SHALL support initialization from its configuration, evaluation on each price tick, notification of fills and cancellations, a stop decision, and generation of grid levels. The engine SHALL invoke these behaviors in a consistent order for every strategy.

#### Scenario: Evaluate a price tick

- **WHEN** a running bot receives a price tick
- **THEN** the engine passes the tick to the selected strategy and applies the resulting decisions

#### Scenario: Strategy requests a stop

- **WHEN** a strategy reports that its stop condition is met
- **THEN** the bot stops placing new orders and follows the standard stop procedure for open orders

### Requirement: Simple grid strategy

The Simple strategy SHALL create a fixed set of levels around a center price using either arithmetic or geometric spacing, and SHALL place a limit order at every level that has no open order.

#### Scenario: Arithmetic spacing

- **WHEN** a Simple strategy is configured with arithmetic spacing, a center price, a level count, and a spacing amount
- **THEN** it generates equally spaced buy levels below the center and sell levels above the center

#### Scenario: Geometric spacing

- **WHEN** a Simple strategy is configured with geometric spacing and a spacing percentage
- **THEN** each successive level differs from its neighbor by the configured percentage

### Requirement: Trailing grid strategy

The Trailing strategy SHALL keep the grid centered near the current market price and move the center only after price moves beyond a configured trailing distance. When the center moves, orders outside the new grid SHALL be canceled and levels inside the new grid SHALL be replenished.

#### Scenario: Price moves beyond trailing distance

- **WHEN** market price moves from the current center by at least the configured trailing distance
- **THEN** the grid center moves to the new price and the level set is recalculated

#### Scenario: Price stays within trailing distance

- **WHEN** market price moves by less than the configured trailing distance
- **THEN** the grid center and its open orders remain unchanged

### Requirement: Martingale grid strategy

The Martingale strategy SHALL increase order size after successive fills by a configured multiplier, subject to a hard maximum order size and a maximum number of increases. Once a hard cap is reached the strategy SHALL stop increasing size and SHALL report the cap event.

#### Scenario: Increase size after a fill

- **WHEN** an order fills and the configured maximum number of increases has not been reached
- **THEN** the next order size is the previous size multiplied by the configured multiplier

#### Scenario: Enforce the hard cap

- **WHEN** the next calculated size would exceed the configured maximum order size
- **THEN** the order is capped at the maximum and no further increases are applied

### Requirement: Dynamic grid strategy

The Dynamic strategy SHALL adjust grid spacing from recent market volatility and SHALL clamp the result to configured minimum and maximum spacing. Spacing SHALL be recalculated no more often than the configured recalculation interval.

#### Scenario: Volatility widens spacing

- **WHEN** recent volatility rises above its previous measurement
- **THEN** the recalculation produces wider spacing, within the configured bounds

#### Scenario: Spacing is clamped

- **WHEN** the calculated spacing falls outside the configured minimum or maximum
- **THEN** the spacing is set to the nearest configured bound

#### Scenario: Respect the recalculation interval

- **WHEN** the recalculation interval has not elapsed since the previous adjustment
- **THEN** the current spacing is retained

### Requirement: Strategy state persistence

Strategy state SHALL be persisted after each tick and fill and SHALL be restored when a bot resumes after a restart. If stored state is missing when required or cannot be interpreted, the bot SHALL pause without placing orders and SHALL surface an error.

#### Scenario: Resume from persisted state

- **WHEN** a bot restarts and valid persisted strategy state exists
- **THEN** the strategy resumes with the same configuration and state it had before the restart

#### Scenario: Refuse to run with unreadable state

- **WHEN** a bot restarts and its persisted strategy state cannot be interpreted
- **THEN** the bot pauses without placing orders and records an error for the user
