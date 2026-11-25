## MODIFIED Requirements

### Requirement: Live-mode safeguards

Live mode SHALL require an explicit confirmation that names the bot and the exchange, enforced by Laravel before a command is published. Before a live bot starts or places its first order, the engine SHALL verify that `TRADING_LIVE_ENABLED` is true and that the exchange reports sufficient balance; a closed gate or shortfall SHALL prevent the start. A closed live gate SHALL pause a live bot and SHALL NOT run it as paper.

#### Scenario: Confirm live mode

- **WHEN** a user switches a bot from paper to live mode
- **THEN** the system requires an explicit confirmation before the mode change is saved

#### Scenario: Insufficient balance

- **WHEN** a live bot is started but the exchange reports a balance below the configured capital allocation
- **THEN** the bot does not start and the shortfall is reported

#### Scenario: Closed live gate does not fall back to paper

- **WHEN** a live bot is running or is started and `TRADING_LIVE_ENABLED` is false
- **THEN** the engine does not place live orders and does not convert the bot to paper mode

## ADDED Requirements

### Requirement: Per-order live risk gates

The engine SHALL apply the same runtime risk limits immediately before every live order place, not only at bot start. Default and production configuration SHALL leave `TRADING_LIVE_ENABLED` false unless an operator explicitly enables it for a named environment.

#### Scenario: Risk gates apply to each live place

- **WHEN** a running live bot attempts to place an order that would breach a risk limit
- **THEN** the engine blocks the place, does not send it to the exchange, and records a risk event

#### Scenario: Default live gate is closed

- **WHEN** the default or production composition starts without an explicit operator override
- **THEN** `TRADING_LIVE_ENABLED` is false
