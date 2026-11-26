# Spec Delta

## ADDED Requirements

### Requirement: Initial evaluation of a new bot

When a running bot has no persisted strategy state, the engine SHALL evaluate its first tick through the strategy's normal tick path so initialization and initial order generation happen in the same decision. The engine SHALL NOT pre-initialize new state in a way that suppresses the strategy's first-tick order intents.

#### Scenario: New bot places its initial ladder

- **WHEN** a running bot with new strategy state is evaluated for the first time
- **THEN** its strategy initializes and emits the initial order intents during that tick

#### Scenario: Dynamic grid first tick

- **WHEN** a new `dynamic-grid` bot is evaluated for the first time
- **THEN** it emits its initial ladder without waiting for `recalculation_interval_seconds`

#### Scenario: Existing state is not reinitialized

- **WHEN** a bot with persisted strategy state is evaluated
- **THEN** the engine uses the existing tick path and does not reinitialize the strategy
