# Spec Delta

## MODIFIED Requirements

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
