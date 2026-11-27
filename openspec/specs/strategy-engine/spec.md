# strategy-engine Specification

## Purpose

Defines how a bot selects and runs a trading strategy, what behavior each supported grid strategy provides, and how strategy state survives restarts.

## Requirements

### Requirement: Strategy selection

A bot SHALL run any strategy registered under a stable identifier. Creating or updating a bot SHALL select the strategy by identifier without requiring changes to the execution engine. An unregistered identifier SHALL be rejected before the bot is saved.

#### Scenario: Select a registered strategy

- **WHEN** a user creates a bot with the identifier of a registered strategy
- **THEN** the bot stores that identifier and the engine resolves it when the bot runs

#### Scenario: Reject an unknown strategy

- **WHEN** a bot is submitted with an identifier that is not registered
- **THEN** validation fails and no bot is created

### Requirement: Strategy lifecycle contract

Every strategy SHALL support initialization from its configuration, evaluation on each price tick, notification of fills and cancellations, a stop decision, and generation of grid levels. The Go engine SHALL invoke these behaviors consistently. Cancellations SHALL be reported through `onCancel`, and a stop decision SHALL use the standard stop procedure. Laravel SHALL store strategy configuration only and SHALL NOT evaluate strategies after cutover.

#### Scenario: Evaluate a price tick

- **WHEN** a running bot receives a price tick
- **THEN** the engine passes the tick to the selected strategy and applies the resulting decisions

#### Scenario: Strategy requests a stop

- **WHEN** a strategy reports that its stop condition is met
- **THEN** the bot stops placing new orders and follows the standard stop procedure for open orders

#### Scenario: Cancellation callback

- **WHEN** the engine cancels an order for a strategy or replaces the grid
- **THEN** the strategy receives `onCancel` for that client order id

#### Scenario: Laravel does not evaluate strategies

- **WHEN** the engine cutover is complete
- **THEN** Laravel does not call strategy `onTick`, `onFill`, or `onCancel` for trading or backtests

### Requirement: Configuration validity at creation

A bot SHALL reject a strategy configuration that cannot produce a valid ladder before it is saved: geometric spacing of `1` or more, any generated level price that is zero or negative, and duplicate level prices. Strategy initialization SHALL continue to enforce the same validity at runtime.

#### Scenario: Reject invalid geometric spacing

- **WHEN** a bot is submitted with geometric spacing of `1` or more
- **THEN** validation fails and no bot is saved

#### Scenario: Reject a non-positive level price

- **WHEN** a bot is submitted with a center price and spacing that would generate a zero or negative level
- **THEN** validation fails and identifies the grid parameter at fault

#### Scenario: Runtime still validates

- **WHEN** a stored configuration cannot be initialized by its strategy at runtime
- **THEN** the bot places no orders and records an error for the user

#### Scenario: Missing state after a completed tick

- **WHEN** a bot has a completed tick but its persisted strategy state is missing
- **THEN** the bot pauses and the missing state is reported instead of reinitializing

### Requirement: Simple grid strategy

The Simple strategy SHALL create a fixed set of levels around a center price using either arithmetic or geometric spacing, and SHALL place a limit order at every armed level that has no open order. A level disarmed after a fill SHALL NOT receive a new order until it is re-armed.

#### Scenario: Arithmetic spacing

- **WHEN** a Simple strategy is configured with arithmetic spacing, a center price, a level count, and a spacing amount
- **THEN** it generates equally spaced buy levels below the center and sell levels above the center

#### Scenario: Geometric spacing

- **WHEN** a Simple strategy is configured with geometric spacing and a spacing percentage
- **THEN** each successive level differs from its neighbor by the configured percentage

#### Scenario: Disarmed level is skipped

- **WHEN** a grid level is disarmed after a fill and has no open order
- **THEN** the strategy does not place a new order at that level

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

### Requirement: Martingale grid strategy

The Martingale strategy SHALL increase order size after successive fills by a configured multiplier, subject to a hard maximum order size and a maximum number of increases. The take-profit sell placed after a buy SHALL use the filled quantity; the increased size SHALL apply to subsequent entry orders. Once a hard cap is reached the strategy SHALL stop increasing size and SHALL report the cap event.

#### Scenario: Increase size after a fill

- **WHEN** an entry order fills and the configured maximum number of increases has not been reached
- **THEN** the next entry order size is the previous size multiplied by the configured multiplier

#### Scenario: Take-profit sell uses the buy quantity

- **WHEN** a buy fills for a quantity and the strategy has increased its size for future entries
- **THEN** the sell at the adjacent higher level uses the filled quantity, not the increased future size

#### Scenario: Enforce the hard cap

- **WHEN** the next calculated size would exceed the configured maximum order size
- **THEN** the order is capped at the maximum and no further increases are applied

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

### Requirement: Dynamic grid state validation

The engine SHALL require the persisted dynamic-grid spacing to be a positive numeric decimal before a due recalculation. Missing, non-numeric, zero, or negative spacing SHALL fail the tick under the existing state-persistence rules, and the strategy SHALL NOT adopt a candidate as recovery.

#### Scenario: Invalid current spacing fails closed

- **WHEN** the persisted spacing is missing, non-numeric, zero, or negative during a due recalculation
- **THEN** the tick fails under the existing state-persistence rules and no new spacing is adopted

### Requirement: Strategy state persistence

Strategy state SHALL be persisted by the engine after each tick and fill and SHALL be restored when a bot resumes after a restart. Laravel SHALL NOT write `strategy_configs.state`. If stored state is missing when required or cannot be interpreted, the bot SHALL pause without placing orders and SHALL surface an error.

#### Scenario: Resume from persisted state

- **WHEN** a bot restarts and valid persisted strategy state exists
- **THEN** the strategy resumes with the same configuration and state it had before the restart

#### Scenario: Refuse to run with unreadable state

- **WHEN** a bot restarts and its persisted strategy state cannot be interpreted
- **THEN** the bot pauses without placing orders and records an error for the user

### Requirement: Go is the only strategy evaluator

After this change the Go engine SHALL be the only component that runs strategy initialization, tick evaluation, and setup plan evaluation. Laravel SHALL NOT keep PHP strategy runtime classes or bindings for Static, Dynamic, Trailing, or Martingale grids, and SHALL NOT call PHP `onTick` to build a preview or a saved grid.

#### Scenario: PHP strategy runtime is removed

- **WHEN** the change is applied
- **THEN** Laravel no longer registers or invokes PHP strategy implementations for ticks or plan preview

#### Scenario: Preview uses the same evaluator as ticks

- **WHEN** a setup is previewed
- **THEN** the levels are produced by the Go strategy implementation that would run for a bot with that configuration

### Requirement: Initial evaluation of a new bot

When a running bot has no persisted strategy state, the engine SHALL evaluate its first tick through the strategy's normal tick path so initialization and initial order generation happen in the same decision. The engine SHALL NOT pre-initialize new state in a way that suppresses the strategy's first-tick order intents.

#### Scenario: New bot places its initial ladder

- **WHEN** a running bot with new strategy state is evaluated for the first time
- **THEN** its strategy initializes and emits the initial order intents during that tick

#### Scenario: Dynamic grid first tick

- **WHEN** a new `dynamic-grid` bot is evaluated for the first time
- **THEN** it emits its initial ladder without waiting for `recalculation_interval_seconds`

#### Scenario: Existing state is not reinitialized

- **WHEN** a bot with persisted strategy state is evaluated
- **THEN** the engine uses the existing tick path and does not reinitialize the strategy
