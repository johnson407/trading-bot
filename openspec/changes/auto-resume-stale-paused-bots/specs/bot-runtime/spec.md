# Spec Delta

## ADDED Requirements

### Requirement: Pause provenance

The engine SHALL persist a pause cause and its recording time in the same update that writes `status` and `last_error`. The cause SHALL be one of a fixed set covering every engine pause. Start, stop, and successful auto-resume SHALL clear both fields. Laravel SHALL NOT write them. A cause SHALL be eligible only while its recorded time equals the bot's `updated_at`.

#### Scenario: Every engine pause records a cause

- **WHEN** the engine pauses a bot for stale tick, risk, protective exit, daily loss, kill-switch, reconciliation conflict, repeated tick failure, live-gate failure, unsupported exchange, strategy stop, or user action
- **THEN** the same update writes the matching cause and its recorded time with `status` and `last_error`

#### Scenario: Start and stop clear provenance

- **WHEN** a bot is started or stopped
- **THEN** the pause cause and recorded time are cleared

#### Scenario: Legacy pause has no cause

- **WHEN** a bot was paused before this change and has no recorded cause
- **THEN** it remains paused until an operator explicitly starts it

#### Scenario: Rollback cannot resurrect a stale cause

- **WHEN** an older engine image changes a paused bot's `status`, `last_error`, or `updated_at` without updating the pause provenance
- **THEN** the recorded time no longer equals `updated_at` and the new engine does not auto-resume that bot

#### Scenario: Laravel does not write provenance

- **WHEN** Laravel starts, pauses, stops, archives, restores, or edits a bot
- **THEN** it does not write the pause cause or recorded time

#### Scenario: Unknown cause is ineligible

- **WHEN** a paused bot has a cause outside the fixed set
- **THEN** the engine treats it as unknown and does not auto-resume it

### Requirement: Stale-pause auto-resume

On engine start, before stale checks, the engine SHALL auto-resume a non-archived paper bot only when status is paused, desired status is running, the exchange is paper-supported, the cause is a current stale-tick pause, and no kill-switch is pending. It SHALL grant first-tick grace, record `bot.auto_resumed`, and clear the cause. Live bots, unsupported exchanges, other causes, and bots already resumed by this process SHALL NOT resume.

#### Scenario: Stale paper pause resumes on engine start

- **WHEN** the engine starts and a non-archived paper bot on a supported exchange is paused with desired status running, a current stale-tick cause, and no pending kill-switch
- **THEN** the engine sets it running, records `bot.auto_resumed`, clears the cause, and gives it the normal first-tick grace before any stale check

#### Scenario: Live stale pause stays paused

- **WHEN** the engine starts and a live bot was stale-paused with desired status running
- **THEN** it remains paused and requires an explicit user start through the normal live gate, credential, and balance checks

#### Scenario: Unsupported paper exchange stays paused

- **WHEN** the engine starts and a stale-paused paper bot uses an exchange the Go runtime does not support for paper trading
- **THEN** it remains paused

#### Scenario: Safety pauses stay paused

- **WHEN** the engine starts and a paused bot with desired status running has a risk-limit, protective-exit, daily-loss, kill-switch, reconciliation-conflict, repeated-tick-failure, live-gate, strategy-stop, or user-pause cause
- **THEN** the bot remains paused and no order is placed

#### Scenario: User pause stays paused

- **WHEN** the engine starts and a paused bot has desired status paused or stopped
- **THEN** the bot remains paused regardless of its recorded pause cause

#### Scenario: Archived bot is not resumed

- **WHEN** the engine starts and a stale-paused bot is archived
- **THEN** the bot remains paused and is excluded from auto-resume

#### Scenario: Restored bot is not started

- **WHEN** an archived stale-paused bot is restored
- **THEN** it remains stopped until an explicit user start, because archiving is limited to stopped bots and the stop transition clears provenance

#### Scenario: Unknown, null, or stale cause stays paused

- **WHEN** the engine starts and a paused bot with desired status running has no cause, an unknown cause, or a cause whose recorded time is not current
- **THEN** the bot remains paused until an operator starts it

#### Scenario: Auto-resume happens once per process

- **WHEN** a bot auto-resumed by this engine process later becomes stale again
- **THEN** it stays paused until the next engine start or an explicit user start

#### Scenario: Auto-resume is observable

- **WHEN** the engine auto-resumes a bot
- **THEN** its activity log records `bot.auto_resumed` and a structured log record identifies the bot and the stale cause
