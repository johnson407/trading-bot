# grid-trading Specification

## Purpose

Defines the grid lifecycle and trading ledger: how levels are created, how orders and fills move through the system, how inventory and profit are accounted for, and how a bot starts, pauses, and stops.

## Requirements

### Requirement: Grid creation

A bot SHALL create a grid around a configured center price with a configured number of levels above and below. Grid creation SHALL reject a center price, level count, or spacing that is missing or non-positive, and SHALL reject a grid whose levels would produce a non-positive price.

#### Scenario: Create a valid grid

- **WHEN** a bot is started with a positive center price, a positive level count, and positive spacing
- **THEN** the system creates that many buy levels below the center and sell levels above the center

#### Scenario: Reject an invalid grid

- **WHEN** any required grid parameter is missing or non-positive, or a level price would be zero or negative
- **THEN** the bot does not start and reports which parameter is invalid

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

### Requirement: Inventory accounting

The system SHALL track base and quote inventory for each bot and SHALL update it on every recorded fill using the filled quantity and price. Inventory SHALL NOT become negative as a result of processing a fill.

#### Scenario: Inventory reflects a buy

- **WHEN** a buy order for a quantity at a price fills
- **THEN** base inventory increases by the quantity and quote inventory decreases by the quantity multiplied by the price

#### Scenario: Prevent negative inventory

- **WHEN** processing a sell would take base inventory below zero
- **THEN** the system rejects the fill update, records an error, and pauses the bot

### Requirement: Profit and loss

The system SHALL calculate and expose realized profit and loss from completed grid cycles and unrealized profit and loss from open positions valued at the latest known price. Values SHALL be available for each bot and for the current session.

#### Scenario: Realized profit after a completed cycle

- **WHEN** a buy fill is followed by the opposite sell fill for the same quantity
- **THEN** realized profit increases by the sell proceeds minus the buy cost

#### Scenario: Unrealized profit update

- **WHEN** the latest market price changes while a position is open
- **THEN** unrealized profit is recalculated using the new price and the open position

### Requirement: Start, pause, and stop

A bot SHALL support start, pause, and stop from Laravel, executed by the engine. Laravel SHALL write `desired_status` only (not `status` or `last_error`) and publish a command. Pause stops new placements and keeps open orders. Stop cancels open orders and marks the bot stopped. Resume from pause SHALL keep state. The engine SHALL honor `desired_status` if Redis is down. A live stop SHALL use Laravel `CancelBotOrders` if publish fails or the heartbeat is missing or expired.

#### Scenario: Pause a running bot

- **WHEN** a running bot is paused
- **THEN** it stops evaluating ticks and placing new orders while existing orders and state remain intact

#### Scenario: Stop a running bot

- **WHEN** a bot is stopped
- **THEN** the system cancels all remaining open orders and marks the bot stopped

#### Scenario: Resume a paused bot

- **WHEN** a paused bot is resumed
- **THEN** it continues from its persisted state without duplicating existing orders

#### Scenario: Status is honored without Redis

- **WHEN** a bot has been marked paused or stopped in Postgres and the command stream is unavailable
- **THEN** the engine still stops new placements and, for stop, cancels remaining open orders after it next reads that status

#### Scenario: Stop cancels live orders when the engine is unavailable

- **WHEN** a user stops a live bot and the engine heartbeat is missing or expired, or publishing the command fails
- **THEN** Laravel sets desired status to stopped and cancels that bot's open live orders in the same action

### Requirement: Cancellation

The system SHALL cancel open orders when a stop is requested, when a strategy requests a stop, or when a risk limit triggers a pause or stop. Cancellation SHALL be idempotent, and an order that is already filled or canceled SHALL NOT be canceled again.

#### Scenario: Cancel remaining orders

- **WHEN** a stop is requested while the bot has open orders
- **THEN** every open order is canceled and recorded as canceled

#### Scenario: Cancellation is idempotent

- **WHEN** cancellation is requested for an order that is already filled or canceled
- **THEN** the request succeeds without changing the order's recorded outcome

### Requirement: Live grid orders are placed by the engine

For a live grid bot the Go engine SHALL place and cancel the grid's limit orders on the selected exchange, subject to the live gate, risk checks, and client-order-id idempotency defined in exchange-connectivity and risk-controls. Paper grid fills remain simulated and SHALL NOT call a private exchange endpoint.

#### Scenario: Live grid place goes through the engine

- **WHEN** a live grid bot needs to open a level and the live and risk gates allow it
- **THEN** the Go engine places the limit order and records the exchange order identifier on that level

#### Scenario: Paper grid does not use private endpoints

- **WHEN** a paper grid bot opens or fills a level
- **THEN** no private exchange request is sent
