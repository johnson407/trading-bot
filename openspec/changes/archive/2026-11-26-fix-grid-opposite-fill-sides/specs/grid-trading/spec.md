# Spec Delta

## MODIFIED Requirements

### Requirement: Order placement per level

The system SHALL maintain at most one open order per grid level and SHALL place limit orders at configured levels using the strategy's side and size. An order placed after a fill SHALL use the side opposite the fill, even when that side differs from the level's configured side. A level with an open order SHALL NOT receive a duplicate order.

#### Scenario: First order at a level

- **WHEN** a grid level has no open order and the bot is running
- **THEN** the system places one limit order at that level

#### Scenario: No duplicate order

- **WHEN** a grid level already has an open order
- **THEN** the system does not place another order at that level

#### Scenario: Post-fill order may differ from the plan side

- **WHEN** a buy fills and the adjacent higher level is configured as a buy level
- **THEN** the system places a sell order at that level

### Requirement: Fill handling

When an order fills, the system SHALL record the fill, close the order, update inventory, and place an order on the side opposite the fill at the adjacent level defined by the strategy. The adjacent level's configured side SHALL NOT determine the order side. A fill that was already recorded SHALL be ignored without changing inventory or creating another order.

#### Scenario: Buy fill places a sell order

- **WHEN** a buy limit order fills at a grid level
- **THEN** the system records the fill, increases base inventory, and places a sell order at the next level above

#### Scenario: Sell fill places a buy order

- **WHEN** a sell limit order fills at a grid level
- **THEN** the system records the fill, decreases base inventory, and places a buy order at the next level below

#### Scenario: Buy fill at an outer level places a sell order

- **WHEN** a buy fills at a level whose adjacent higher level is configured as a buy level
- **THEN** the system places a sell order at that adjacent higher level, not another buy

#### Scenario: Sell fill at an outer level places a buy order

- **WHEN** a sell fills at a level whose adjacent lower level is configured as a sell level
- **THEN** the system places a buy order at that adjacent lower level, not another sell

#### Scenario: Duplicate fill is ignored

- **WHEN** the system receives a fill notification whose exchange identifier matches an already recorded fill
- **THEN** the duplicate is ignored and inventory and open orders are unchanged

## ADDED Requirements

### Requirement: Take-profit quantity after a buy fill

A sell placed after a buy fill SHALL use the filled quantity. It SHALL NOT use an increased quantity the strategy has reserved for future entry orders.

#### Scenario: Take-profit sell uses the filled quantity

- **WHEN** a buy fills for a quantity and the strategy has increased its size for future entries
- **THEN** the sell at the adjacent higher level uses the filled quantity, not the increased future size

### Requirement: Level re-arming after fills

A filled grid level SHALL be re-armed only when the order at its paired adjacent price fills on the side opposite the original fill. A same-side fill at another level SHALL NOT re-arm it. Re-arming SHALL NOT create a duplicate order for a level that already has an open order.

#### Scenario: Paired opposite fill re-arms the original level

- **WHEN** a buy fills at a level and the paired sell at the adjacent higher price later fills
- **THEN** the original buy level is re-armed and may receive a new buy order

#### Scenario: Same-side fill does not re-arm the original level

- **WHEN** a second buy fills at a level above the first buy level, at the first level's paired price
- **THEN** the first buy level stays disarmed and no new buy is placed there

#### Scenario: Multiple same-side fills in one tick

- **WHEN** the live runtime fills all six buy levels in one tick before placing post-fill orders, with no base inventory
- **THEN** each filled buy places its opposite sell at its own adjacent level (50, 60, 70, 80, 90, and 110 for a center of 100 and 10 spacing), all six buy levels stay disarmed, and the next tick places no buys above the market

#### Scenario: Re-arm does not duplicate an existing order

- **WHEN** a level is re-armed while it already has an open order
- **THEN** no additional order is placed at that level

### Requirement: Unfilled paired orders

If the order at a filled level's paired adjacent price is canceled or is never placed, the level SHALL remain disarmed until the strategy state is reset or the pairing is reconciled. The system SHALL NOT place a new order at that level solely because it has no open order.

#### Scenario: Canceled paired order leaves the level disarmed

- **WHEN** the order at a filled level's paired adjacent price is canceled before it fills
- **THEN** the original level remains disarmed and receives no replacement order
