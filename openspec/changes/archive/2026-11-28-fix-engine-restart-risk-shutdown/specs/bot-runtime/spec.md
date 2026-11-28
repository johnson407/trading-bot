# Spec Delta

## ADDED Requirements

### Requirement: Restart grace for stale checks

The engine SHALL require both the last successful tick and the time this process first scheduled a running bot to be older than the effective stale threshold before pausing it. Downtime before this process started SHALL NOT by itself make a resumed bot stale.

#### Scenario: Long downtime does not pause on restart

- **WHEN** the engine starts after a shutdown longer than the stale threshold and a bot was running when the engine stopped
- **THEN** the bot is not paused for staleness before it has had a chance to tick, and it continues running

#### Scenario: Restart grace does not hide a failed tick

- **WHEN** a bot's ticks keep failing after engine start and both the process grace period and the stale threshold have elapsed
- **THEN** the engine pauses the bot and records the stale health-check event

#### Scenario: Stale bot detected after grace

- **WHEN** a running bot has not completed a tick within the effective stale threshold and it has been scheduled by this engine process for at least that threshold
- **THEN** the engine pauses the bot and a health-check event is visible

### Requirement: Graceful engine shutdown

On `SIGINT` or `SIGTERM` the engine SHALL stop starting new work and SHALL drain in-flight ticks, command consumers, the heartbeat, market-data work, and the stale-backtest sweeper within a bounded timeout before exiting. If the drain timeout expires the engine SHALL log it and exit without waiting further.

#### Scenario: In-flight work drains before exit

- **WHEN** a shutdown signal arrives while a tick or command is in flight
- **THEN** the engine stops starting new work and waits for the in-flight work to finish or for the drain timeout to expire

#### Scenario: Clean shutdown

- **WHEN** all tracked background work finishes within the drain timeout
- **THEN** the process exits without cutting off a tick or command between its durable writes and its final effect

#### Scenario: Drain timeout

- **WHEN** tracked background work does not finish within the drain timeout
- **THEN** the engine logs the timeout and exits without starting new work
