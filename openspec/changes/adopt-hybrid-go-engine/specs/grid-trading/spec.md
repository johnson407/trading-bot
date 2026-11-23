# Spec Delta

## MODIFIED Requirements

### Requirement: Start, pause, and stop

A bot SHALL support start, pause, and stop transitions initiated from Laravel and executed by the Go engine. Laravel SHALL record the desired status in Postgres and publish a command on the engine command stream. Pause SHALL stop new placements while preserving open orders and state. Stop SHALL cancel remaining open orders and mark the bot stopped. Resume from pause SHALL continue without losing state. The engine SHALL honor paused or stopped Postgres rows if the command stream is down.

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
