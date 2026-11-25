# Spec Delta

## MODIFIED Requirements

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
