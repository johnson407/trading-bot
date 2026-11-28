# Tasks

## 1. Durable engine-event ingestion

- [x] 1.1 Add a read-only `alerts:check-source-duplicates` command that lists duplicate `(source_type, source_id)` alert groups and exits non-zero; add nullable `alert_processed_at` and `alert_ingest_attempts` columns plus partial `id` indexes to `risk_events` and `activity_logs`, add a unique index on `alerts(source_type, source_id)`, and backfill existing source rows as processed in the same migration; verify the command reports a seeded duplicate, the migration test shows pre-existing rows processed and new rows unprocessed, and pre-existing duplicate alert sources abort with a clear message.
- [x] 1.2 Extend `AlertRecorder` with result-returning methods for risk events, activity logs, and source-less system incidents (`created`, `deduplicated`, `not_configured`, `disabled`, `failed`); a unique `alerts(source_type, source_id)` violation SHALL return `deduplicated` and load the existing source alert rather than surfacing as `failed`. Verify unit tests cover every result, the unique-source collision, null source fields, and preservation of the existing `fromRiskEvent`/`fromActivity` signatures.
- [x] 1.3 Make `RiskEventRecorder` and `ActivityRecorder` mark their own source rows processed after their synchronous alert attempt for every result except `failed`; verify a Laravel-created event is not re-alerted by the scanner.
- [x] 1.4 Add `alerts:ingest-engine-events` with a bounded unlock candidate-id query, then one transaction per id that re-selects the row `FOR UPDATE SKIP LOCKED` and marks it processed for every result except `failed`; failure attempts increment in their own transaction. Verify feature tests for risk events, activity logs, processed rows, non-alertable rows, bucket-boundary retries, a Laravel-written row, a poison row that stops after three attempts, and two concurrent workers.
- [x] 1.5 Add `alerts.engine_ingest_enabled` (default true), register the scanner every ten seconds with `withoutOverlapping(5)` and `onOneServer()`, and add the heartbeat check for each account with a non-archived live bot that is running or paused; verify scheduler registration, switch-off behavior, outage alert, sustained-outage dedupe, recovery, and no trading-state change.
- [x] 1.6 Align engine event types and severities: `risk.daily_loss_limit` critical, `risk.stale_bot` warning, live cancellation failure `risk.live_cancel_failed` critical, local cancellation failure `risk.cancel_failed` warning, and keep `risk.kill_switch_activated` non-alertable. Add catalog entries for `risk.cancel_failed`, `risk.live_start_refused`, `risk.live_order_rejected`, and `engine.heartbeat_missing`; verify engine tests, `tests/Feature/AlertConfigTest.php`, and a two-way test over the fixture's runtime emitters (legacy catalog entries excluded) cover the design table.
- [x] 1.7 Update `docs/alerts.md` (including its Test map), `docs/engine.md`, `docs/runtime.md`, and `docs/risk.md` with the bridge, markers, event names, heartbeat alert, retry/poison behavior, and operational limits; verify the documented names match `config/alerts.php` and the emitted engine events, and run `php artisan test --filter='RiskDocsTest|AlertConfigTest'` with the standard test-environment overrides.

## 2. Integration verification

- [x] 2.1 Run the full PHP suite, the full engine suite, and `openspec validate fix-engine-alert-delivery --strict`; verify all pass and record the exact commands.

## Verification record

- Full PHP suite (Go plan server on `trading-bot-plan-test:18080`):
  `docker run --rm --network trading-bot_default -v "$PWD:/var/www/html" -v /home/johnson/Repos/trading-bot/vendor:/var/www/html/vendor -v /home/johnson/Repos/trading-bot/.env:/var/www/html/.env:ro -w /var/www/html -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e DB_HOST=trading-bot-postgres-1 -e DB_USERNAME=postgres -e DB_PASSWORD=secret -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync -e ENGINE_PLAN_URL=http://trading-bot-plan-test:18080 --entrypoint php trading-bot-app:latest artisan test`
  Result: **1 skipped, 741 passed (5258 assertions)**.
- Full engine suite:
  `docker run --rm --network trading-bot_default -v "$PWD:/src" -w /src/engine -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod -e RUNTIME_TEST_DSN='postgres://postgres:secret@trading-bot-postgres-1:5432/trading_bot_test?sslmode=disable' golang:1.24-alpine sh -c 'gofmt -w internal/runtime/*.go && go vet ./... && go test ./... -count=1'`
  Result: every package `ok`; runtime 7.521s; gofmt and vet clean.
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` -> no errors.
- Pint on changed files fixed two style issues; no remaining style failures.
- `openspec validate fix-engine-alert-delivery --strict` -> valid.

## Workflow follow-up

- Request review of the Laravel migration, alert bridge, engine event changes, and specs before merging.
- Archive the change with the OpenSpec archive skill after review and verification.
