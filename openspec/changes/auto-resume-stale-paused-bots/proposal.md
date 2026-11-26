# Proposal

## Why

The engine can leave a bot `status = paused` while `desired_status = running` after a stale-tick pause; on 2026-11-24 Bot 2 needed a manual start. The restart-grace fix prevents downtime from creating new stale pauses, but it does not recover bots that were already stale-paused, and the engine currently has no safe way to distinguish a stale pause from a risk, exit, kill-switch, or daily-loss pause that must never resume automatically.

## What Changes

- Persist the cause of every engine-initiated pause and the time it was recorded on the bot, in the same update as `status` and `last_error`, owned by the engine.
- On engine start, before stale checks run, auto-resume a bot only when all of these hold: it is not archived, it is a paper bot on the supported paper exchange, `status = paused`, `desired_status = running`, the recorded cause is a stale-tick pause, and the provenance is current.
- Grant an auto-resumed bot the normal first-tick grace, record a `bot.auto_resumed` activity and a structured log record, and clear the pause cause.
- Never auto-resume a live bot in this change. A stale-paused live bot requires an explicit user start so the normal live gate, credential, and balance checks run.
- Never auto-resume a bot paused by risk limits, protective exits, daily loss, kill-switch, reconciliation conflict, live-gate failure, unsupported exchange, strategy stop, repeated tick failure, or a user pause/stop. A pause recorded before this change has no cause and stays paused until an operator resumes it.
- Reject a cause whose recorded time does not match the bot's `updated_at`, so an old engine image during rollback cannot leave a stale cause that the new engine later trusts.
- Auto-resume runs at most once per bot per engine process; a bot that becomes stale again stays paused until the next process start or an explicit user start, so a persistent fault cannot flap.
- Make the engine wait for the `pause_reason` schema to be ready before starting any worker, without adding an app-health dependency to production Compose.
- Add tests proving stale paper pauses resume and every other pause stays paused.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: Adds pause-provenance and stale-pause auto-resume requirements; desired status remains authoritative and the engine still never resumes against a user pause or stop.
- `risk-controls`: Adds an automatic-resume scope requirement that reserves auto-resume for current stale-tick pauses on paper bots and keeps risk, protective-exit, daily-loss, kill-switch, live, and user pauses paused.

## Impact

- Database: one forward migration adding an engine-owned `pause_reason` and `pause_reason_recorded_at` to `bots`, with a bounded cause check and no backfill.
- Go engine: a typed pause helper for every pause path in `engine/internal/runtime`, startup schema readiness, startup recovery, structured logging, and integration tests.
- Laravel: engine-owned activity type list in `app/Domain/Activity/ActivityRecorder.php`; no Laravel status writes and no new HTTP surface.
- Documentation: `docs/runtime.md`, `docs/engine.md`, `docs/risk.md`, `AGENTS.md`, and the status-ownership table for the resume rule and pause causes.
- No production Compose service-order change, no change to risk thresholds, exit rules, live gating, or strategy behavior.
