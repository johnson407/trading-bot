# Spec Delta

## MODIFIED Requirements

### Requirement: Deterministic strategy replay

The Go engine SHALL replay the snapshotted strategy through the same lifecycle used for live execution — initialization, tick, fill, cancel, and stop — in a side-effect-free simulation that does not write bot, order, fill, risk, or activity records. Laravel SHALL NOT replay strategies after cutover.

#### Scenario: Strategy lifecycle is honored

- **WHEN** the engine replays a candle sequence
- **THEN** it calls initialization once, ticks on each subsequent candle, and reports each fill and cancellation to the strategy

#### Scenario: First candle initializes and ticks

- **WHEN** the engine processes the first candle
- **THEN** it calls initialization and then a tick with the initialized state, and initial grid creation does not trigger an extra tick

#### Scenario: Multiple fills are processed sequentially

- **WHEN** more than one order fills on the same candle
- **THEN** each fill updates the portfolio and is reported to the strategy before the next fill is processed

#### Scenario: Same-candle gap may defer an opposite order

- **WHEN** more than one buy order fills on the same candle and the adjacent higher level is still open
- **THEN** the opposite sell for that fill is not placed during that candle, and the filled level remains disarmed for the rest of the replay unless a paired fill re-arms it

#### Scenario: Simulation is isolated from live data

- **WHEN** a backtest runs
- **THEN** no live bot, order, fill, risk, or activity record is created or modified
