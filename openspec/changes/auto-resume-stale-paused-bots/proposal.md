# Proposal

## Why

The engine can leave a bot `status = paused` while `desired_status = running` after a stale-tick pause; on 2026-11-24 Bot 2 needed a manual start. The restart-grace fix prevents downtime from creating new stale pauses, but it does not recover bots that were already stale-paused, and the engine currently has no safe way to distinguish a stale pause from a risk, exit, kill-switch, or daily-loss pause that must never resume automatically.

## What Changes

- Persist the cause of every engine-initiated pause on the bot, owned by the engine alongside `status` and `last_error`.
- On engine start, before stale checks run, auto-resume a bot only when all of these hold: it is not archived, `status = paused`, `desired_status = running`, and the recorded pause cause is a stale-tick pause.
- Grant an auto-resumed bot the normal first-tick grace, record a `bot.auto_resumed` activity and a structured log record, and clear the pause cause.
- Never auto-resume a bot paused by risk limits, protective exits, daily loss, kill-switch, reconciliation conflict, live-gate failure, or a user pause/stop; a pause recorded before this change has no cause and stays paused until an operator resumes it.
- Auto-resume runs at most once per bot per engine process; a bot that becomes stale again stays paused until the next process start or an explicit user start, so a persistent fault cannot flap.
- Add tests proving stale pauses resume and every safety pause stays paused.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: Adds a stale-pause auto-resume requirement; desired status remains authoritative and the engine still never resumes against a user pause or stop.
- `risk-controls`: Adds an automatic-resume scope requirement that reserves auto-resume for stale pauses and keeps risk, protective-exit, daily-loss, and kill-switch pauses paused.

## Impact

- Database: one forward migration adding an engine-owned pause-cause column to `bots`.
- Go engine: pause paths in `engine/internal/runtime`, startup reconciliation, structured logging, and integration tests.
- Laravel: engine-owned activity type list in `app/Domain/Activity/ActivityRecorder.php`; no Laravel status writes and no new HTTP surface.
- Documentation: `docs/runtime.md`, `docs/engine.md`, and `docs/risk.md` for the resume rule and pause causes.
- No change to risk thresholds, exit rules, live gating, or strategy behavior.
