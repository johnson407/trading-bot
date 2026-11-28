# Design

## Context

See `proposal.md` - Why for the operational gap. The engine writes `risk_events` and `activity_logs` with direct SQL and does not call Laravel. `AlertRecorder` already owns catalog lookup, sanitization, dedupe, source links, and post-commit email dispatch, so it remains the single writer for alerts.

The scheduler runs `schedule:work` as a separate service and already hosts `alerts:prune`. The two source tables have `id`, `user_id`, `bot_id`, `type`, `severity`, `message`, `context`, and `created_at`, with no processed marker. Laravel's `RiskEventRecorder` and `ActivityRecorder` create their source row and then synchronously call `AlertRecorder`; the bridge must not alert those rows a second time.

## Goals / Non-Goals

**Goals:**

- Give engine-written safety events the same alert and email behavior as Laravel-written events.
- Make ingestion restart-safe, idempotent, and safe to run on the existing scheduler.
- Keep the bridge isolated from trading: a slow, failed, or disabled alert path cannot pause, cancel, or place anything.
- Alert only the accounts exposed to a heartbeat outage.

**Non-Goals:**

- Moving alert creation into the Go engine or adding Postgres triggers.
- Changing alert retention, inbox behavior, or the existing 15-minute dedupe window.
- Alerting on routine engine activity such as ordinary fills or balance sync.
- Adding new channels beyond the existing in-app inbox and critical-alert email.

## Decisions

### Scheduled scanner with a runtime switch

Add one command, `alerts:ingest-engine-events`, scheduled every ten seconds with `withoutOverlapping(5)` and `onOneServer()`. It scans unprocessed rows from both tables in bounded batches. The schedule is registered unconditionally and checks `alerts.engine_ingest_enabled` (`ALERTS_ENGINE_INGEST_ENABLED`, default true), so rollout and rollback do not require editing code or the scheduler definition.

Triggers were rejected because they would put Laravel mail/queue work inside the engine's write path and make engine migrations depend on Laravel behavior. Engine callbacks were rejected because the engine may write safety events while Laravel is down; the durable database rows are the source of truth.

### Durable processed markers and one alert per source

Add nullable `alert_processed_at`, `alert_ingest_attempts`, and nullable `alert_next_attempt_at` columns to `risk_events` and `activity_logs`, with a partial index on `id` where `alert_processed_at IS NULL`. Existing rows are backfilled as processed by adding `alert_processed_at` with a temporary stable default and immediately dropping the default, avoiding a full-table rewrite. Indexes are created concurrently and the migration sets a short lock timeout so the engine's hot write path is not blocked during deploy.

Add a unique index on `alerts(source_type, source_id)` for non-null sources. Add a read-only `alerts:check-source-duplicates` command that lists every `(source_type, source_id)` group with more than one alert and exits non-zero when any exist. Run it against production before the deploy. If duplicates remain, the migration aborts with the same report rather than deleting alert history. Resolution is to delete the newer alert rows by id, keeping the oldest alert with its message, context, source, and delivery, then re-run the migration. This makes a retry across a dedupe-window boundary unable to create a second alert for the same source.

Laravel's `RiskEventRecorder` and `ActivityRecorder` mark their own source rows processed after their synchronous alert attempt for every result except failure. The scanner therefore handles engine-written rows and failed Laravel rows, and never re-alerts a Laravel event.

### Per-row transaction with bounded poison-row handling

The scanner first selects candidate ids with a short read-only query: `SELECT id FROM <table> WHERE alert_processed_at IS NULL AND (alert_next_attempt_at IS NULL OR alert_next_attempt_at <= now()) ORDER BY id LIMIT :batch`, with no locks. For each id it opens one transaction and re-selects that row with `WHERE id = :id AND alert_processed_at IS NULL FOR UPDATE SKIP LOCKED`, skipping it when another worker has already taken or processed it. The lock is therefore held for the whole per-row transaction, and two workers cannot process the same row. A row is marked processed for `created`, `deduplicated`, `not_configured`, and `disabled`; any `Throwable` rolls back only that row's transaction. A failure increments `alert_ingest_attempts` and sets `alert_next_attempt_at` with exponential backoff in its own short transaction, so the count survives. Failed rows are never marked processed; after six failures the scanner creates one source-keyed `alerts.ingestion_failed` alert and keeps retrying, so a transient outage cannot silently discard safety alerts.

`AlertRecorder` gains result-returning methods for risk events, activity logs, and source-less system incidents. The result is one of `created`, `deduplicated`, `not_configured`, `disabled`, or `failed`. A unique violation on `alerts(source_type, source_id)` is reported as `deduplicated`, not `failed`: the recorder catches that violation, loads the alert with the same source, and returns `deduplicated`, so the scanner marks the row processed instead of retrying it as a poison row. Existing `fromRiskEvent` and `fromActivity` signatures stay for their current callers. A source-less system incident stores null source fields, which the alerts table already allows.

### One event vocabulary

Change engine emissions at the source rather than translating in Laravel.

| Emitted type | Emitter | Catalog key and severity |
|---|---|---|
| `risk.daily_loss_limit` | daily-loss pause in `tick.go` | `risk.daily_loss_limit`, critical |
| `risk.stale_bot` | stale-tick pause in `runtime.go` | `risk.stale_bot`, warning |
| `risk.live_cancel_failed` | live cancellation failure in `tick.go` | `risk.live_cancel_failed`, critical |
| `risk.cancel_failed` | local cancellation-state update failure in `tick.go` | `risk.cancel_failed`, warning |
| `risk.live_start_refused` | refused live start in `runtime.go` | `risk.live_start_refused`, critical |
| `risk.live_order_rejected` | rejected live order in `live.go` | `risk.live_order_rejected`, critical |
| `engine.heartbeat_missing` | heartbeat monitor | `engine.heartbeat_missing`, critical |
| `risk.kill_switch_activated` | per-bot engine kill-switch pause | non-alertable; scanner marks processed |

The engine kill-switch event stays non-alertable. The dedupe key includes the bot, so renaming the per-bot engine event to `kill_switch.activated` would create one critical email per bot instead of one account alert. Laravel's `ActivateKillSwitch` already records the account-level `kill_switch.activated` activity, which remains the single kill-switch notification.

Runtime-emitted alertable types and their production emitters are kept in a shared test fixture. The test fails when a runtime-emitted alertable type is missing from `config/alerts.php` or a fixture entry names an emitter file that does not emit that type. Configured incidents with no runtime emitter — `risk.reconciliation_conflict`, `risk.strategy_stop_cancel_failed`, `risk.daily_loss_cancel_failed`, `risk.failure_cancel_failed`, and `risk.exit_cancel_failed` — are marked `legacy` and excluded from the reverse check; they remain catalogued for historical rows or future wiring.

### Heartbeat alert scoped to exposed accounts

The same scheduled command checks `EngineHeartbeat`. When the heartbeat is missing or expired, it records `engine.heartbeat_missing` for each user with a non-archived live bot whose status is running or paused. Accounts with no such live bot get no heartbeat alert.

A global alert has no natural owner because alerts require a user. Alerting every account would spam paper-only users. Alerting only exposed live accounts matches the risk the outage creates. Dedupe uses the existing 15-minute tumbling bucket, so a sustained outage creates at most one alert per account per window and a later outage after recovery creates a new alert.

## Risks / Trade-offs

- [A row is alerted but the marker update fails] -> The unique source index makes the retry idempotent; the marker is in the same transaction as the alert.
- [A source row is marked processed after a transient alert failure] -> Only `failed` rolls back; the row is retried with backoff and never discarded, and repeated failure raises a visible `alerts.ingestion_failed` alert.
- [The scanner falls behind during a burst] -> Batches are bounded, the run is non-overlapping for five minutes, and the next run continues from the remaining rows.
- [The scanner adds load to PostgreSQL] -> Partial `alert_processed_at IS NULL` indexes, small batches, and a ten-second cadence limit the cost.
- [Engine event rename changes existing dashboards or tests] -> Update engine tests, docs, and type-based queries in the same change; old rows remain historical.
- [Kill-switch engine events create no alert] -> Intentional; the account-level Laravel alert already covers the incident once.
- [Heartbeat alert does not fire for paper-only users] -> Intentional: the dead-man alert is for live exposure; the dashboard still shows the outage to everyone.
- [Email delivery outage loses a critical notification] -> The alert remains in the inbox; delivery failures are logged and never roll back the source event.

## Migration Plan

1. Run `alerts:check-source-duplicates` against production and resolve any reported duplicates by deleting the newer alert rows and keeping the oldest. Add the markers, attempt counters, next-attempt timestamps, partial indexes, and the unique alert source index concurrently; backfill existing source rows as processed without a full-table rewrite. The migration aborts if duplicate source alerts still exist.
2. Add `alerts.engine_ingest_enabled` and register the schedule entry.
3. Deploy the engine event-name changes and the Laravel scanner together with the switch enabled.
4. Verify one engine-shaped event produces one alert and one marker, and that a repeated run creates no duplicate.
5. Rollback: set `ALERTS_ENGINE_INGEST_ENABLED=false` and restart the scheduler, then revert the engine event names. The marker columns are additive and can remain.

## Open Questions

None.
