# Spec Delta

## ADDED Requirements

### Requirement: Stale-pause auto-resume

On engine start, before stale checks, the engine SHALL auto-resume a non-archived bot only when its status is paused, its desired status is running, and its recorded pause cause is a stale-tick pause. It SHALL grant the normal first-tick grace, record a `bot.auto_resumed` activity, and clear the pause cause. No other pause cause, and no bot already auto-resumed by this process, SHALL resume automatically.

#### Scenario: Stale pause resumes on engine start

- **WHEN** the engine starts and a non-archived bot is paused with desired status running and a recorded stale-tick cause
- **THEN** the engine sets it running, records `bot.auto_resumed`, clears the cause, and gives it the normal first-tick grace before any stale check

#### Scenario: Risk pause stays paused

- **WHEN** the engine starts and a paused bot with desired status running has a risk-limit pause cause
- **THEN** the bot remains paused and no order is placed

#### Scenario: User pause stays paused

- **WHEN** the engine starts and a paused bot has desired status paused or stopped
- **THEN** the bot remains paused regardless of its recorded pause cause

#### Scenario: Archived bot is not resumed

- **WHEN** the engine starts and a stale-paused bot is archived
- **THEN** the bot remains paused and is excluded from auto-resume

#### Scenario: Unknown or legacy cause stays paused

- **WHEN** the engine starts and a paused bot with desired status running has no recorded pause cause
- **THEN** the bot remains paused until an operator starts it

#### Scenario: Auto-resume happens once per process

- **WHEN** a bot auto-resumed by this engine process later becomes stale again
- **THEN** it stays paused until the next engine start or an explicit user start

#### Scenario: Auto-resume is observable

- **WHEN** the engine auto-resumes a bot
- **THEN** its activity log records `bot.auto_resumed` and a structured log record identifies the bot and the stale cause
