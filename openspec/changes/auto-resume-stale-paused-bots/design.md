# Design

## Context

See `proposal.md` for motivation and the `bot-runtime` and `risk-controls` deltas for the contract. `Runtime.reconcile` runs every second and currently handles desired-status drift, cancellation retries, and stale pauses. Its desired-status query omits `paused + running`, so a stale-paused bot is never reconsidered. Engine pauses go through `pause` or `markPaused`, which write `status` and `last_error` but leave `desired_status` untouched. Laravel archives bots with a soft delete (`deleted_at`). The engine already has a first-schedule grace map used by the stale check.

## Goals / Non-Goals

**Goals:**

- Recover bots that were stale-paused before this engine process started, without operator action.
- Persist enough provenance to distinguish a stale pause from every safety pause.
- Make the resume idempotent and race-safe against a concurrent user pause, stop, or archive.
- Keep every risk, exit, daily-loss, kill-switch, reconciliation, live-gate, and failure pause paused.

**Non-Goals:**

- No continuous auto-resume or retry loop during normal operation.
- No change to stale thresholds, tick scheduling, risk thresholds, or exit rules.
- No automatic promotion of paper bots to live or resumption of live trading beyond the normal start gate.
- No change to Laravel-owned `desired_status`.

## Decisions

### Store the pause cause in an engine-owned `bots.pause_reason` column

Add a nullable `pause_reason` text column with values such as `stale_tick`, `risk_limit`, `protective_exit`, `daily_loss`, `kill_switch`, `reconciliation_conflict`, `tick_failure`, `live_gate`, `strategy_stop`, and `unsupported_exchange`. Every engine pause path writes the cause in the same UPDATE as `status` and `last_error`; the start and stop transitions clear it. The column is not added to Laravel's fillable fields because it stays engine-owned like `status`. No backfill is performed: a paused bot from before the migration has a null cause and stays paused until an operator starts it once.

Alternatives: infer the cause from the latest `bot.stale` activity is query-heavy, races with later activity, and cannot cover pauses that do not write an activity; store it in `bots.settings` avoids a migration but mixes a control-plane field into a JSON settings bag and complicates Laravel merge rules; parse `last_error` text is brittle.

### Run recovery once at the first reconcile, before desired drift and stale checks

`Runtime` gains an `autoResumePending` flag initialized true. The first `reconcile` pass calls `autoResumeStalePausedBots`, then clears the flag whether or not individual bots were resumed; a query error leaves it true so the next pass retries. The query selects non-deleted bots with `status = 'paused'`, `desired_status = 'running'`, and `pause_reason = 'stale_tick'`. Each candidate is processed under the existing per-bot lock and updated with a conditional statement:

```sql
UPDATE bots
SET status = 'running', pause_reason = NULL, last_error = NULL, updated_at = now()
WHERE id = $1 AND deleted_at IS NULL AND status = 'paused'
  AND desired_status = 'running' AND pause_reason = 'stale_tick'
  AND kill_switch_requested_at IS NULL
RETURNING id
```

A zero-row result means a user or another writer changed the bot first, so the resume is skipped. The update leaves `last_successful_tick_at` untouched; the existing first-schedule grace grants the bot a full threshold before the stale check can pause it again. Because the recovery query runs only once per process, a bot that becomes stale again stays paused until the next process start or an explicit user start, which prevents flapping.

Alternatives: run recovery on every reconcile would resume a bot immediately after each stale pause and could loop against a persistent fault; resume only on a timer adds state and a second failure mode; resume based on `last_error` or activity lookup cannot reliably distinguish safety pauses.

### Receive the migration before the engine starts

The engine depends on the new column, so the production engine service waits for the application service to become healthy instead of only for PostgreSQL. The application service owns migrations, so this ordering guarantees the column exists before the new engine queries it. The migration is additive and forward-only; an old engine ignores the column during rollback.

### Record `bot.auto_resumed` and keep the log secret-free

On a successful resume, insert a `bot.auto_resumed` activity with the stale cause in its metadata and emit a structured log record with bot ID and cause. Add the type to the engine-owned list in `app/Domain/Activity/ActivityRecorder.php`. The resume never writes `desired_status`, never places orders, and does not change inventory.

### Tests mirror the split

Go unit tests cover eligibility and the conditional update predicate. Integration tests use `RUNTIME_TEST_DSN` and cover: stale cause resumes; risk, protective-exit, daily-loss, kill-switch, tick-failure, reconciliation, live-gate, and unknown causes stay paused; user-paused and stopped desired statuses stay paused; soft-deleted bots stay paused; a resumed bot that becomes stale again is not resumed a second time in the same process; and `bot.auto_resumed` is recorded. A Laravel test keeps the engine activity type list and documentation fixture in sync.

## Risks / Trade-offs

- [A null cause after the migration leaves old paused bots paused] → this is the conservative default; the runbook tells the operator to start such a bot once.
- [The engine starts before migrations in a non-Compose environment] → production Compose adds the app-health dependency; outside Compose, operators must migrate before starting the engine, as with any schema-dependent release.
- [A stale pause caused by a persistent fault resumes and pauses again after the grace period] → recovery is once per process, so the loop is bounded to one attempt per restart.
- [Pause-cause values drift between Go and docs] → a single Go constant set plus a documented table; unknown values are never eligible.
- [A user pause racing with startup recovery] → the conditional update requires `desired_status = 'running'` and `status = 'paused'` at write time, so the user's newer intent wins.

## Migration Plan

1. Add the nullable `pause_reason` column; no backfill, no data rewrite.
2. Add the app-health dependency to the production engine service.
3. Deploy the application migration and the new engine image together.
4. Rollback restores the previous engine image; the extra nullable column is harmless and stays.

## Open Questions

None.
