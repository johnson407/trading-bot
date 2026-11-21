# Spec Delta

## MODIFIED Requirements

### Requirement: Paper fill simulation

Paper mode SHALL simulate a limit order fill when the market price reaches or crosses the order price. Simulated fills SHALL be recorded and processed through the same order and inventory behavior as live fills. Paper fills SHALL be evaluated from the market last observed when a tick runs, so a price that crosses and recrosses between ticks is not guaranteed to fill.

#### Scenario: Simulate a buy fill

- **WHEN** the market price falls to or below an open paper buy order's price
- **THEN** the order is filled at its limit price and the fill is recorded

#### Scenario: Simulate a sell fill

- **WHEN** the market price rises to or above an open paper sell order's price
- **THEN** the order is filled at its limit price and the fill is recorded

#### Scenario: Cross and recross between ticks

- **WHEN** the market price crosses an open paper order's limit and returns before the next tick runs
- **THEN** no fill is guaranteed, and the system does not claim the order was filled
