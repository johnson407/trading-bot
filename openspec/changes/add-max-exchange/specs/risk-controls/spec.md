# Spec Delta

## ADDED Requirements

### Requirement: MAX live orders require a venue gate

A live bot on MAX SHALL be allowed to start and to place orders only when the global live-trading gate is open, the MAX venue gate `TRADING_MAX_LIVE_ENABLED` is open, the per-bot typed confirmation matches, and the free-quote balance check passes. The MAX venue gate SHALL default to closed. When a check fails the engine SHALL pause the bot with the live-gate cause and cancel its resting orders, as for any other live bot, and SHALL NOT fall back to paper.

#### Scenario: MAX venue gate closed

- **WHEN** a live MAX bot is started while `TRADING_LIVE_ENABLED` is true and `TRADING_MAX_LIVE_ENABLED` is false
- **THEN** the bot is paused with the live-gate cause, a critical risk event is recorded, and no order request is sent to MAX

#### Scenario: All MAX gates open

- **WHEN** both gates are open, the confirmation matches, and the free-quote balance covers the bot's capital
- **THEN** the bot may start and place orders subject to the existing risk limits

#### Scenario: Gate closes while running

- **WHEN** the MAX venue gate is closed and the engine restarts with a running live MAX bot
- **THEN** the bot is paused with the live-gate cause and its resting MAX orders are cancelled
