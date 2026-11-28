# Proposal

## Why

The Go engine records safety events directly in `risk_events` and `activity_logs`, but Laravel's alert recorder only runs for Laravel-side events. Engine pauses, protective exits, daily-loss pauses, refused live starts, rejected live orders, and cancellation failures therefore create no inbox alert and send no email, so an operator can miss a live safety stop. The engine heartbeat also produces only a dashboard warning when it expires.

## What Changes

- Add a scheduled Laravel command that scans unprocessed engine-written `risk_events` and `activity_logs`, records alerts through the existing `AlertRecorder`, and marks each row processed. Laravel-side recorders mark their own rows processed after their synchronous alert attempt, so the scanner handles engine-written and failed Laravel rows only.
- Add nullable `alert_processed_at` and `alert_ingest_attempts` markers plus partial indexes to both source tables. The migration backfills existing rows as processed so enabling the scanner does not replay historical events as an alert storm.
- Make ingestion retry-safe: per-row transactions, `FOR UPDATE SKIP LOCKED` batches, a unique source index on `alerts`, and a bounded poison-row retry before marking processed.
- Align engine-emitted event types with the alert catalog:
  - daily-loss pause becomes `risk.daily_loss_limit` critical;
  - stale-tick pause becomes `risk.stale_bot` warning;
  - live cancellation failure uses `risk.live_cancel_failed` critical, while local cancellation failure uses `risk.cancel_failed` warning;
  - engine `risk.kill_switch_activated` stays non-alertable so the single account-level Laravel `kill_switch.activated` alert is not duplicated per bot;
  - add catalog entries for `risk.cancel_failed`, `risk.live_start_refused`, `risk.live_order_rejected`, and `engine.heartbeat_missing`.
- Add a heartbeat monitor that creates one deduplicated critical `engine.heartbeat_missing` alert per exposed account when the Redis heartbeat is missing or expired. It must not automatically pause bots, cancel orders, or activate the kill switch.
- Gate the scanner and heartbeat monitor with `alerts.engine_ingest_enabled` so rollout and rollback do not depend on editing the schedule.
- Add end-to-end tests that insert engine-shaped rows, run the scanner, assert alert content/source/deduplication/email dispatch, and assert non-alertable and historical events are marked processed.
- Update `docs/alerts.md`, `docs/engine.md`, `docs/runtime.md`, and `docs/risk.md` with the event bridge, event names, heartbeat behavior, and operational limits.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `alerts`: extend covered incidents with engine-originated pauses, refused live starts, rejected live orders, and cancellation failures; require engine-written source events to become alerts; define the heartbeat outage alert and source-less system alerts.
- `bot-runtime`: require a scheduled heartbeat dead-man alert while preserving the rule that a missing heartbeat never automatically pauses, cancels, or activates the kill switch; permit the bridge's processing markers on the otherwise insert-only activity log.

## Impact

- Laravel: new console command and scheduler entry, `AlertRecorder` result methods and source-less system-incident entry point, `RiskEvent`/`ActivityLog` models, a migration adding processed markers/attempts/indexes and a unique alert source index, `config/alerts.php`, a config switch, and alert tests.
- Engine: event-name and severity changes in `engine/internal/runtime/tick.go`, `runtime.go`, and `live.go`; no engine schema change and no trading behavior change beyond the persisted event vocabulary.
- Database: the Laravel migration adds shared columns and indexes used by both writers; it backfills historical rows as processed.
- Operations: critical engine alerts now queue email, so production requires a working mailer; the existing 90-day alert retention and 15-minute dedupe window apply.
