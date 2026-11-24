# Spec Delta

## MODIFIED Requirements

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

### Requirement: Strategy state persistence

Strategy state SHALL be persisted by the engine after each tick and fill and SHALL be restored when a bot resumes after a restart. Laravel SHALL NOT write `strategy_configs.state`. If stored state is missing when required or cannot be interpreted, the bot SHALL pause without placing orders and SHALL surface an error.

#### Scenario: Resume from persisted state

- **WHEN** a bot restarts and valid persisted strategy state exists
- **THEN** the strategy resumes with the same configuration and state it had before the restart

#### Scenario: Refuse to run with unreadable state

- **WHEN** a bot restarts and its persisted strategy state cannot be interpreted
- **THEN** the bot pauses without placing orders and records an error for the user
