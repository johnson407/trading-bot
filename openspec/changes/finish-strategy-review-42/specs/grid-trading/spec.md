## MODIFIED Requirements

### Requirement: Order placement per level

The system SHALL maintain at most one open order per grid level and SHALL place limit orders at configured levels using the strategy's side and size. An order placed after a fill SHALL use the side opposite the fill, even when that differs from the level's configured side. A level with an open order SHALL NOT receive a duplicate. When capital or the open-order limit is short, intents nearest the current market price SHALL be considered first, breaking ties by lower price.

#### Scenario: First order at a level

- **WHEN** a grid level has no open order and the bot is running
- **THEN** the system places one limit order at that level

#### Scenario: No duplicate order

- **WHEN** a grid level already has an open order
- **THEN** the system does not place another order at that level

#### Scenario: Post-fill order may differ from the plan side

- **WHEN** a buy fills and the adjacent higher level is configured as a buy level
- **THEN** the system places a sell order at that level

#### Scenario: Nearest orders win when the limit is short

- **WHEN** the open-order limit or available capital cannot fund every intent
- **THEN** the intents closest to the market price are placed first, regardless of side

### Requirement: Unfilled paired orders

If the order at a filled level's paired adjacent price is canceled or is never placed, the level SHALL remain disarmed until the strategy state is reset, the pairing is reconciled, or a ladder recalculation removes the paired price. The system SHALL NOT place a new order at that level solely because it has no open order. Inventory held after a canceled take-profit SHALL remain available to native sell levels rather than being auto-sold by a replacement order.

#### Scenario: Canceled paired order leaves the level disarmed

- **WHEN** the order at a filled level's paired adjacent price is canceled before it fills
- **THEN** the original level remains disarmed and receives no replacement order

#### Scenario: Reset reconciles the pairing

- **WHEN** the strategy state is reset after a paired order was canceled
- **THEN** the disarm state is cleared and normal ladder placement resumes

#### Scenario: Inventory exits through native levels

- **WHEN** inventory remains after its take-profit order is canceled
- **THEN** it can be sold by an armed native sell level and is not automatically re-offered at a loss
