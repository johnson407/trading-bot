# Spec Delta

## ADDED Requirements

### Requirement: Dynamic grid order replenishment

The Dynamic strategy SHALL, on every tick that is not due for a spacing recalculation, retain the current spacing and emit a limit order for each armed level that has no open order. Levels with an open order SHALL be skipped. The same replenishment SHALL restore missing orders after cancellations, rejected orders, position-limit skips, pause/resume, or a grid replacement.

#### Scenario: Replenish orders before the next recalculation

- **WHEN** a dynamic-grid tick is not due for a spacing recalculation and an armed level has no open order
- **THEN** the strategy retains the current spacing and emits an order for that level

#### Scenario: Existing orders are not duplicated

- **WHEN** a dynamic-grid tick is not due for a spacing recalculation and an armed level already has an open order
- **THEN** the strategy does not emit another order for that level

#### Scenario: Restore orders after a grid replacement

- **WHEN** a grid replacement cancels the open orders and the new ladder shares levels with the replaced ladder
- **THEN** the next evaluation emits orders for those shared armed levels instead of leaving them empty

#### Scenario: Disarmed levels remain skipped

- **WHEN** a dynamic-grid level is disarmed after a fill and has no open order
- **THEN** replenishment does not place a new order at that level until it is re-armed

### Requirement: Martingale sell-fill reset

After every sell fill, the Martingale strategy SHALL reset the current quantity to the base order quantity, the increase count to zero, and the cap flag to false. The next buy entry SHALL use the reset base quantity. A sell fill is an exit whether it completes a take-profit for a filled buy or sells starting inventory. A take-profit sell placed by a buy fill SHALL use the filled buy quantity for its own quantity.

#### Scenario: Sell fill resets size

- **WHEN** a martingale sell fill occurs after an increased entry
- **THEN** the current quantity returns to the base order quantity, the increase count returns to zero, and the cap flag is false

#### Scenario: Starting-inventory sell resets size

- **WHEN** a martingale sell fill occurs with no prior buy entry (starting inventory)
- **THEN** the current quantity is the base order quantity, the increase count is zero, and the cap flag is false

#### Scenario: Reset applies to the next entry

- **WHEN** a martingale sell fill resets the size and the next entry order is emitted
- **THEN** that entry uses the base order quantity rather than the previous increased quantity

#### Scenario: Sell at the cap clears the cap

- **WHEN** a martingale sell fill occurs while the cap flag is true
- **THEN** the cap flag becomes false and the next entry uses the base order quantity

#### Scenario: Sell fill does not increase size

- **WHEN** a martingale sell order fills
- **THEN** the strategy does not multiply the current quantity and does not append a cap event for that fill

### Requirement: Martingale size configuration validity

A martingale configuration SHALL require `max_order_quantity` to be greater than or equal to `base_order_quantity`, `size_multiplier` to be greater than `1`, and `max_increases` to be greater than or equal to `0`. Bot creation and update SHALL reject invalid values before saving, strategy initialization SHALL reject them before placing orders, and every existing-state tick SHALL validate them before placing orders.

#### Scenario: Reject maximum below base at creation

- **WHEN** a martingale bot is submitted with `max_order_quantity` below `base_order_quantity`
- **THEN** validation fails on the maximum-order-quantity field and no bot is saved

#### Scenario: Reject maximum below base at initialization

- **WHEN** a stored martingale configuration has `max_order_quantity` below `base_order_quantity`
- **THEN** initialization fails and the bot places no orders

#### Scenario: Reject invalid configuration on a resumed bot

- **WHEN** a martingale bot with persisted state is evaluated and its configuration has `max_order_quantity` below `base_order_quantity`
- **THEN** the tick fails before any order is placed and the invalid configuration is surfaced

#### Scenario: Reject an invalid multiplier

- **WHEN** a martingale configuration has `size_multiplier` of `1` or less
- **THEN** validation fails on the multiplier field and no bot is saved

#### Scenario: Reject invalid maximum increases

- **WHEN** a martingale configuration has a negative `max_increases`
- **THEN** validation fails on the maximum-increases field and no bot is saved

### Requirement: Grid strategy spacing compatibility

Trailing and martingale strategies SHALL accept only arithmetic or geometric spacing. A configuration that selects dynamic spacing for either strategy SHALL be rejected before saving and at strategy initialization. The dynamic grid SHALL ignore `spacing_type` and derive its ladder from `min_spacing` and `max_spacing`; the dynamic-grid form omits the shared spacing fields.

#### Scenario: Trailing rejects dynamic spacing

- **WHEN** a trailing-grid configuration selects dynamic spacing
- **THEN** validation fails before saving and initialization rejects the configuration

#### Scenario: Martingale rejects dynamic spacing

- **WHEN** a martingale configuration selects dynamic spacing
- **THEN** validation fails before saving and initialization rejects the configuration

#### Scenario: Dynamic grid ignores dynamic spacing

- **WHEN** the dynamic-grid strategy is initialized or ticked with `spacing_type` set to `dynamic`
- **THEN** the strategy does not reject the configuration and builds its ladder from `min_spacing` and `max_spacing`

## MODIFIED Requirements

### Requirement: Martingale grid strategy

The Martingale strategy SHALL increase size after successive buy entry fills by a configured multiplier, subject to a hard maximum size and maximum number of increases. A sell fill SHALL NOT increase size and SHALL reset the cycle to the base quantity. The take-profit sell after a buy SHALL use the filled quantity; increased size applies to subsequent buy entries. A hard cap stops increases for the current cycle and reports the cap event.

#### Scenario: Increase size after a fill

- **WHEN** a buy entry order fills and the configured maximum number of increases has not been reached
- **THEN** the next buy entry order size is the previous size multiplied by the configured multiplier

#### Scenario: Take-profit sell uses the buy quantity

- **WHEN** a buy fills for a quantity and the strategy has increased its size for future entries
- **THEN** the sell at the adjacent higher level uses the filled quantity, not the increased future size

#### Scenario: Enforce the hard cap

- **WHEN** the next calculated size would exceed the configured maximum order size
- **THEN** the order is capped at the maximum and no further increases are applied for the current cycle
