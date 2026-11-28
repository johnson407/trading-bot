# Spec Delta

## ADDED Requirements

### Requirement: Paper bots run on every exchange with a market-data source

A paper bot SHALL run, and a stale-paused paper bot SHALL be eligible for auto-resume, whenever its exchange has a registered market-data source, not only for Binance. A paper bot whose exchange has no registered source SHALL be paused with the unsupported-exchange cause and SHALL NOT be auto-resumed. Registered sources SHALL include Binance and MAX.

#### Scenario: MAX paper bot runs

- **WHEN** a paper bot with exchange `max` is started
- **THEN** it is not paused for an unsupported exchange and its ticks use MAX public prices

#### Scenario: MAX paper bot auto-resumes after a stale pause

- **WHEN** the engine starts and a MAX paper bot is paused only for a stale tick with desired status running
- **THEN** it is resumed under the same conditions as a Binance paper bot

#### Scenario: Unknown exchange is still paused

- **WHEN** a paper bot is started with an exchange that has no registered market-data source
- **THEN** it is paused with the unsupported-exchange cause and is not auto-resumed
