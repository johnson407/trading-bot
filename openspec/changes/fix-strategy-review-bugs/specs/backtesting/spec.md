# Spec Delta

## MODIFIED Requirements

### Requirement: Deterministic strategy replay

The Go engine SHALL replay the snapshotted strategy through the same first-tick and subsequent-tick lifecycle used for live execution — initialization inside the normal tick path, tick, fill, cancel, and stop — in a side-effect-free simulation that does not write bot, order, fill, risk, or activity records. Laravel SHALL NOT replay strategies after cutover.

#### Scenario: Strategy lifecycle is honored

- **WHEN** the engine replays a candle sequence
- **THEN** it evaluates the first candle through the strategy's normal tick path with new state, evaluates each subsequent candle, and reports each fill and cancellation to the strategy

#### Scenario: First candle initializes and ticks

- **WHEN** the engine processes the first candle
- **THEN** it calls the strategy's normal tick with a new state so initialization and initial order generation happen in one decision, and it does not call initialization separately in a way that suppresses first-candle orders

#### Scenario: Dynamic first candle places its ladder

- **WHEN** a dynamic-grid replay starts with a recalculation interval longer than one candle
- **THEN** the first candle places the initial ladder without waiting for the interval to elapse

#### Scenario: Grid replacement re-places the new ladder

- **WHEN** a candle causes a grid replacement that cancels the open simulated orders
- **THEN** the new ladder receives orders for its armed levels, including levels whose prices were also present in the replaced ladder

#### Scenario: A second evaluation that returns no orders does not empty the ladder

- **WHEN** the engine re-evaluates a replaced grid and the second evaluation returns no orders
- **THEN** the orders already computed for that candle's new ladder are not discarded

#### Scenario: Multiple fills are processed sequentially

- **WHEN** more than one order fills on the same candle
- **THEN** each fill updates the portfolio and is reported to the strategy before the next fill is processed

#### Scenario: Same-candle gap may defer an opposite order

- **WHEN** more than one buy order fills on the same candle and the adjacent higher level is still open
- **THEN** the opposite sell for that fill is not placed during that candle, and the filled level remains disarmed for the rest of the replay unless a paired fill re-arms it

#### Scenario: Simulation is isolated from live data

- **WHEN** a backtest runs
- **THEN** no live bot, order, fill, risk, or activity record is created or modified
