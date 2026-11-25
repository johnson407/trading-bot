## MODIFIED Requirements

### Requirement: Go trading engine process

A dedicated Go engine process SHALL evaluate running bots, consume market data, place and sync paper and live orders, enforce runtime risk, reconcile on start and periodically, and run backtests. After cutover Laravel SHALL NOT evaluate ticks, place or sync orders, or replay backtests. Laravel MAY cancel open live orders only during a user-triggered kill-switch or stop when the heartbeat is missing or expired or command publish fails. v1 has one active command consumer.

#### Scenario: Engine evaluates a running bot

- **WHEN** a bot is running and its tick interval has elapsed
- **THEN** the Go engine performs the evaluation and persists the result without a Laravel tick job

#### Scenario: Laravel does not place trading orders

- **WHEN** the engine cutover is complete
- **THEN** Laravel HTTP workers and Horizon do not place, cancel, or synchronize trading orders on the exchange, except for the user-triggered live kill-switch or stop emergency cancel when the engine is unavailable or command publish fails

#### Scenario: Laravel emergency cancel on live kill-switch or stop

- **WHEN** a user activates the kill-switch or stops a live bot and the engine heartbeat is missing or expired, or command publish fails
- **THEN** Laravel cancels the affected live bots' open orders in the same action via `CancelBotOrders`

### Requirement: Restart recovery

On engine start the system SHALL resume bots that were running and reload their persisted strategy state. For live bots the engine SHALL reconcile local open orders with the exchange before processing new ticks, and SHALL also reconcile those orders periodically while it is running. Paper bots SHALL reconcile locally and SHALL NOT make private exchange calls. The Laravel scheduler SHALL NOT be responsible for pre-dispatch reconciliation after cutover.

#### Scenario: Resume a running bot

- **WHEN** the engine process starts and a bot was running before shutdown
- **THEN** the bot resumes from persisted state and continues processing ticks

#### Scenario: Reconcile open orders

- **WHEN** a resumed live bot has locally open orders
- **THEN** the system confirms each order's status with the exchange, applies partial fills to inventory while leaving the remainder open, and updates or removes orders that are fully filled or canceled

#### Scenario: Reconcile before scheduling

- **WHEN** the engine container starts with running live bots
- **THEN** those bots' open orders are reconciled before new ticks are evaluated

#### Scenario: Periodic reconcile

- **WHEN** a live bot has been running and the order-sync interval elapses
- **THEN** the engine confirms open orders with the exchange again and updates local state to match

#### Scenario: Paper reconcile stays local

- **WHEN** a paper bot is resumed or reaches a sync interval
- **THEN** it reconciles from local state and no private exchange request is sent

#### Scenario: Partial fill is applied

- **WHEN** reconcile sees a live order as partially filled
- **THEN** inventory is updated for the filled quantity, the remainder stays open, and no invented fill is recorded

#### Scenario: Reconcile respects rate limits

- **WHEN** a live reconcile request is rejected as rate limited
- **THEN** the engine backs off and does not immediately retry that request

## ADDED Requirements

### Requirement: Engine-owned live placement

Live order placement SHALL be performed by the Go engine when `TRADING_LIVE_ENABLED` is true. A closed gate SHALL pause or refuse a live bot and SHALL NOT run it as paper.

#### Scenario: Engine places live orders

- **WHEN** a live bot is running, `TRADING_LIVE_ENABLED` is true, and risk gates allow a limit order
- **THEN** the Go engine places the order on the selected exchange and records the result

#### Scenario: Closed gate does not fall back to paper

- **WHEN** a live bot is running or is started and `TRADING_LIVE_ENABLED` is false
- **THEN** the engine does not place live orders and does not convert the bot to paper mode
