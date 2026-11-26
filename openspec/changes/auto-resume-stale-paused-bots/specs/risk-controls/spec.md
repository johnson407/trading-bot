# Spec Delta

## ADDED Requirements

### Requirement: Automatic resume scope

Automatic resume SHALL apply only to an engine-recorded stale-tick pause. A pause caused by a risk limit, protective exit, daily loss, kill-switch, reconciliation conflict, repeated tick failure, live-gate failure, or user action SHALL NOT resume automatically and SHALL remain paused until an operator explicitly resumes it.

#### Scenario: Risk-limit pause is not resumed

- **WHEN** a bot is paused by a risk limit and its desired status is running
- **THEN** it remains paused after an engine start and places no orders

#### Scenario: Protective-exit pause is not resumed

- **WHEN** a bot is paused by a stop-loss, take-profit, or range exit
- **THEN** it remains paused until an operator resumes it, even if the price later returns inside the threshold

#### Scenario: Daily-loss pause is not resumed

- **WHEN** a bot is paused by a per-bot or account daily-loss limit
- **THEN** it remains paused across the day reset and engine restarts until an operator resumes it

#### Scenario: Kill-switch pause is not resumed

- **WHEN** the kill-switch pauses bots
- **THEN** no bot resumes automatically, including after an engine restart

#### Scenario: Repeated tick-failure pause is not resumed

- **WHEN** a bot is paused after three consecutive tick failures
- **THEN** it remains paused until an operator resumes it

#### Scenario: Only stale pauses are eligible

- **WHEN** a paused bot with desired status running is evaluated for auto-resume
- **THEN** it resumes only when the recorded pause cause is a stale-tick pause
