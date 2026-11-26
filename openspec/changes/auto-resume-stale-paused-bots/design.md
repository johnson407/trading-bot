# Design

## Context

See `proposal.md` for motivation and the `bot-runtime` and `risk-controls` deltas for the contract. `Runtime.reconcile` runs every second and currently handles desired-status drift, cancellation retries, and stale pauses. Its desired-status query omits `paused + running`, so a stale-paused bot is never reconsidered. Engine pauses go through `pause` or `markPaused`, which write `status` and `last_error` but leave `desired_status` untouched. Several paths also write `status` directly. Laravel archives bots with a soft delete (`deleted_at`). The engine already has a first-schedule grace map used by the stale check.

## Goals / Non-Goals

**Goals:**

- Recover paper bots that were stale-paused before this engine process started, without operator action.
- Persist enough provenance to distinguish a current stale pause from every safety pause and from a stale cause left by an older engine image.
- Make the resume idempotent and race-safe against a concurrent user pause, stop, or archive.
- Keep every live, risk, exit, daily-loss, kill-switch, reconciliation, live-gate, unsupported-exchange, strategy-stop, tick-failure, and user pause paused.
- Fail closed before workers start if the new schema is not ready.

**Non-Goals:**

- No continuous auto-resume or retry loop during normal operation.
- No automatic resume of live bots. A live bot requires an explicit user start so the normal live gate, credentials, and balance check run.
- No change to stale thresholds, tick scheduling, risk thresholds, or exit rules.
- No automatic promotion of paper bots to live.
- No change to Laravel-owned `desired_status`.
- No production Compose service-order dependency.

## Decisions

### Store a bounded pause cause and its recording time

`reconciliation_conflict` remains reserved for the reconciliation-conflict path required by the runtime spec; if that path is implemented, it must use this cause. Its absence today does not make any existing pause eligible.

Add nullable `pause_reason` text and `pause_reason_recorded_at` timestamptz columns to `bots`, with a check constraint limiting `pause_reason` to the fixed set: `stale_tick`, `risk_limit`, `protective_exit`, `daily_loss`, `kill_switch`, `reconciliation_conflict`, `tick_failure`, `live_gate`, `strategy_stop`, `unsupported_exchange`, and `user`. The column is not added to Laravel's fillable fields because it stays engine-owned like `status`.

Every pause path writes the cause and `pause_reason_recorded_at = now()` in the same UPDATE as `status`, `last_error`, and `updated_at = now()`. This includes the `pause` helper, `markPaused`, the direct unsupported-exchange update, both kill-switch paths, the desired-pause path, `pauseOtherBots`, and `tickFailure`. A typed `pauseCause` constant set is the only source of values. Start, stop, and successful auto-resume clear both fields. A user pause writes `user`; it can never be eligible because `desired_status` is paused.

Provenance is self-validating. Auto-resume requires `pause_reason_recorded_at = updated_at`. A newer engine writes both timestamps with the same transaction's `now()`. A previous engine image does not know either field, so any status, error, or `updated_at` write it makes after a new-engine stale pause leaves the recorded time behind. The new engine then treats the cause as stale and stays paused. This closes the rollback hole without inspecting `last_error` text. A failed cancellation that sets `cancel_retry_requested`, or any other write that changes `updated_at` such as a Laravel config edit, also makes the cause stale; this is conservative and avoids trusting a status write from an older engine. The operator can still start the bot explicitly.

No backfill is performed: a paused bot from before the migration has a null cause and stays paused until an operator starts it once. Unknown values are impossible through the check constraint and are still treated as ineligible in code.

Alternatives: infer the cause from the latest `bot.stale` activity is query-heavy, races with later activity, and cannot cover pauses that do not write an activity; store it in `bots.settings` avoids a migration but mixes a control-plane field into a JSON settings bag and complicates Laravel merge rules; parse `last_error` text is brittle; a separate pause-history table adds joins and retention work for a single current-state decision.

### Run recovery once at the first reconcile, for paper bots only

`Runtime` gains an `autoResumePending` flag initialized true. The first `reconcile` pass calls `autoResumeStalePausedBots` before desired-status drift and stale checks. The flag is cleared only after the candidate query and every candidate update complete without a transient error; a query or per-bot update error leaves it true so the next pass retries. A zero-row conditional update caused by a concurrent change is not an error and does not keep the flag set.

The query selects non-deleted bots with `status = 'paused'`, `desired_status = 'running'`, `mode = 'paper'`, `exchange = 'binance'` (the only paper-supported exchange in this runtime), `pause_reason = 'stale_tick'`, `pause_reason_recorded_at = updated_at`, and `kill_switch_requested_at IS NULL`. Each candidate is processed under the existing per-bot lock and updated with a conditional statement:

```sql
UPDATE bots
SET status = 'running', pause_reason = NULL, pause_reason_recorded_at = NULL,
    last_error = NULL, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL AND status = 'paused'
  AND desired_status = 'running' AND mode = 'paper' AND exchange = 'binance'
  AND pause_reason = 'stale_tick' AND pause_reason_recorded_at = updated_at
  AND kill_switch_requested_at IS NULL
RETURNING id
```

A zero-row result means a user or another writer changed the bot first, so the resume is skipped. Paper-only scope means no live gate, credential, or balance check is bypassed; a live stale pause stays paused and needs an explicit start. Binance-only scope avoids bypassing the runtime's unsupported-paper-exchange guard. The update leaves `last_successful_tick_at` untouched; the existing first-schedule grace grants the bot a full threshold before the stale check can pause it again. Because recovery runs only once per process, a bot that becomes stale again stays paused until the next process start or an explicit user start, which prevents flapping.

Alternatives: resume every eligible bot including live would bypass the start gates; run recovery on every reconcile would resume a bot immediately after each stale pause and could loop against a persistent fault; resume only on a timer adds state and a second failure mode; resume based on `last_error` or activity lookup cannot reliably distinguish safety pauses.

### Wait for the schema before starting workers

The engine depends on the new columns, but adding `depends_on: app: condition: service_healthy` would push app startup (up to about 150 seconds) plus engine startup (up to about 75 seconds) against the deployer's 180-second activation budget. Instead, the engine performs a bounded schema-readiness probe before constructing the pool consumers, scheduler, or market feed. The probe checks for both columns and exits with a clear configuration error after a bounded wait. In production the app owns the migration and normally finishes first; if migration fails, the engine fails closed and the deployer rolls back. Outside Compose, operators must migrate before starting the engine, as with any schema-dependent release.

Alternatives: an app-health dependency is simpler but risks false rollbacks and changes the deployment contract; starting workers and failing pause writes later is unsafe; an engine-side migration would duplicate migration ownership.

### Record `bot.auto_resumed` and keep the log secret-free

On a successful resume, insert a `bot.auto_resumed` activity with the stale cause in its metadata and emit a structured log record with bot ID and cause. Add the type to the engine-owned list in `app/Domain/Activity/ActivityRecorder.php`. If the activity insert fails, the engine logs the failure with the bot ID; the status update is not rolled back because a resumed paper bot with a missing activity is still safe, and the next startup attempt is unaffected. The resume never writes `desired_status`, never places orders, and does not change inventory.

### Tests mirror the split

Go unit tests cover eligibility, the schema probe, and the conditional update predicate. Integration tests use `RUNTIME_TEST_DSN` and cover: a current stale paper cause resumes; a live bot, unsupported paper exchange, risk, protective-exit, daily-loss, kill-switch, tick-failure, reconciliation, live-gate, strategy-stop, and user cause stay paused; unknown, null, and stale-timestamp causes stay paused; user-paused and stopped desired statuses stay paused; soft-deleted bots stay paused; a resumed bot that becomes stale again is not resumed a second time in the same process; a per-bot update error retries on the next pass; and `bot.auto_resumed` is recorded. A Laravel test keeps the engine activity type list and documentation fixture in sync. A rollback test simulates an older engine changing `updated_at` without touching provenance and asserts no resume.

## Risks / Trade-offs

- [A null cause after the migration leaves old paused bots paused] → this is the conservative default; the runbook tells the operator to start such a bot once.
- [The engine starts before migrations in a non-Compose environment] → the bounded schema probe fails closed before workers; operators must migrate first.
- [A stale pause caused by a persistent fault resumes and pauses again after the grace period] → recovery is once per process, so the loop is bounded to one attempt per restart.
- [Live stale-paused bots still need an operator] → deliberate: live starts must pass the gate, credential, and balance checks, so this change does not auto-resume them.
- [Pause-cause values drift between Go and docs] → a single Go constant set, a database check constraint, and a documented table; unknown values are never eligible.
- [A user pause racing with startup recovery] → the conditional update requires `desired_status = 'running'` and `status = 'paused'` at write time, so the user's newer intent wins.
- [An older engine could change status without updating `updated_at`] → every current pause path sets `updated_at`; the provenance timestamp-equality rule is tested against an old-engine-style update.

## Migration Plan

1. Add nullable `pause_reason` and `pause_reason_recorded_at` columns and the bounded cause check; no backfill, no data rewrite.
2. Deploy the application migration and the new engine image together.
3. The engine's schema probe waits for both columns before starting workers.
4. Rollback restores the previous engine image; the extra nullable columns stay. A stale cause left by the rollback is ignored because its recorded time no longer equals `updated_at`.

## Open Questions

None.
