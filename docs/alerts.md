# Alerts

Alerts turn operational incidents into something the owner actually sees. The
system already records risk events and activity logs; alerts are the durable,
owner-scoped notification layer on top of them.

## What creates an alert

Alert creation has two paths. Laravel-side incidents go through
`RiskEventRecorder` and `ActivityRecorder`, which record the source row and attempt
the alert synchronously. Engine-written incidents are picked up by the scheduled
`alerts:ingest-engine-events` command. Both paths use the same `AlertRecorder`
catalog, deduplication, sanitizer, and email delivery.

Only the types listed in `config/alerts.php` produce an alert. Every other risk
event or activity entry is still written to its original table and remains visible
in the bot activity feed.

| Type | Severity | Meaning |
| --- | --- | --- |
| `risk.daily_loss_limit` | critical | A bot hit its daily-loss limit and was paused. |
| `exchange.failure_threshold` | critical | Repeated exchange failures paused a bot. |
| `risk.stale_bot` | warning | A running bot's tick loop stopped making progress. |
| `risk.reconciliation_conflict` | critical | Local state disagreed with the exchange and the bot was paused. |
| `risk.live_trading_disabled` | critical | The live-trading gate paused a bot. |
| `kill_switch.activated` | critical | The account-wide kill switch paused bots and canceled orders. This alert has no bot. |
| `risk.daily_loss_cancel_failed` | critical | Orders could not be canceled after a daily-loss pause. |
| `risk.kill_switch_cancel_failed` | critical | Orders could not be canceled after the kill switch. |
| `risk.strategy_stop_cancel_failed` | critical | Orders could not be canceled after a strategy stop. |
| `risk.live_cancel_failed` | critical | Orders could not be canceled after a live-gate pause. |
| `risk.failure_cancel_failed` | critical | Orders could not be canceled after a consecutive-failure pause. |
| `risk.stop_loss_triggered` | critical | A protective stop-loss paused a bot and canceled its open orders. |
| `risk.take_profit_triggered` | warning | A protective take-profit paused a bot and canceled its open orders. |
| `risk.range_exit_triggered` | warning | A protective range bound paused a bot and canceled its open orders. |
| `risk.cancel_failed` | warning | An open order could not be canceled and the pause retry marker was set. |
| `risk.live_start_refused` | critical | The engine refused to start a live bot, for example because the gate was closed or the balance check failed. |
| `risk.live_order_rejected` | critical | The exchange rejected a live order and no open order was recorded. |
| `engine.heartbeat_missing` | critical | The engine heartbeat is missing or expired for an account with a live bot. This alert has no bot. |

Cancellation failures are critical because they leave orders live after the bot
has been paused. Stop-loss and exit-cancellation alerts are emailed; take-profit
and range exits are warning-level and stay in the in-app inbox.

These events do **not** alert, because they are normal trading behavior or already
visible in the activity feed:

- `risk.position_limit`
- `risk.insufficient_inventory`
- `order.min_amount`
- `order.min_notional`
- `order.level_missing`
- routine order, fill, balance, and bot lifecycle events

### Deduplication

Repeated occurrences of the same type for the same owner and bot are suppressed
inside a fixed window. The default is 900 seconds (15 minutes), configured with
`ALERTS_DEDUPE_WINDOW_SECONDS`. The window is a tumbling bucket, not a rolling
timer: the first occurrence in a bucket is stored, later ones in that bucket are
dropped, and the next bucket can create a new alert. Account-level incidents use
the bot key `account`.

The unique index on `(dedupe_key, dedupe_bucket)` makes this safe when two workers
record the same incident concurrently. The `exchange.failure_threshold` pair
(activity plus risk event) collapses to one alert for the same reason. A unique
index on `(source_type, source_id)` also prevents a retry across a bucket boundary
from creating a second alert for the same source row; that outcome is
`deduplicated`.

Each alert keeps a polymorphic `source_type`/`source_id` pointing at the risk
event or activity log that produced it. Archiving a bot keeps the alert's
`bot_id` and the association, so the inbox can still show the bot name as plain
text. Permanently deleting a bot sets the alert's `bot_id` to null; the alert
itself is retained.

### Engine event ingestion

The engine writes `risk_events` and `activity_logs` directly. The
`alerts:ingest-engine-events` command scans unprocessed rows, creates at most one
alert per source row, and marks the row processed. It runs every ten seconds with
`withoutOverlapping(5)` and `onOneServer()` so multiple schedulers do not duplicate
work.

- `risk_events` and `activity_logs` carry nullable `alert_processed_at` and
  `alert_ingest_attempts` markers. The migration backfills existing rows as
  processed so deployment does not replay history.
- Laravel recorders mark their own rows processed after the synchronous attempt,
  so the scanner only handles engine-written rows and failed Laravel attempts.
- A failed alert attempt rolls back that row, increments the attempt count in a
  separate transaction, and retries on a later run. After three failures the row
  is marked processed and a warning is logged so one poison row cannot block the
  batch.
- `alerts:check-source-duplicates` is a read-only pre-deployment check. The
  migration aborts if duplicate alert sources exist. Resolve them by keeping the
  oldest alert for each source, preserving its message, context, source, and
  delivery, and deleting the newer duplicates.
- Disable ingestion and heartbeat checks with
  `ALERTS_ENGINE_INGEST_ENABLED=false`; the schedule stays registered and exits
  immediately.
- The engine's per-bot `risk.kill_switch_activated` event is deliberately not
  alertable. Laravel records the single account-level `kill_switch.activated`
  activity, so renaming the engine event would create one critical alert per bot.

The heartbeat check alerts once per account, per deduplication window, when the
engine heartbeat is missing or expired and the account has a non-archived live bot
that is running or paused. It never pauses a bot, cancels an order, or activates
the kill switch. HTTP health and dashboard reads only show the outage; they do not
create alerts.

## Delivery channels

Every alert is stored in the in-app inbox. Critical alerts are also emailed to the
owner through the queued `SendAlertEmail` job; warnings stay in-app only.

- Email is sent with `AlertMail`, which includes the title, bot name when present,
  sanitized message and observed values, timestamp, and a link to the inbox.
- The job has three tries and a 60s/300s backoff. A terminal failure is logged as
  `alert.email_failed_terminal`; a missing alert is a no-op.
- Email is dispatched only after the surrounding database transaction commits. A
  rolled-back pause creates no alert and sends no email.
- Transport or queue failures are logged and never rethrown into the trading path.
  A pause still pauses the bot, and the in-app alert remains available.
- If storing the alert itself fails, the insert is rolled back to its savepoint,
  the error is logged with sanitized text, and the pause or tick continues. The
  source risk event or activity log remains committed.
- Only verified email addresses are used. Unverified or missing owners are skipped
  with a warning log.

**`MAIL_MAILER=log` does not deliver email.** It writes the message to the
application log, which is the default in many local environments. Configure a real
mailer for production alerts; the in-app inbox works regardless.

Alert messages and context are sanitized before storage. Credential-like keys
(`api_key`, `api_secret`, `password`, `token`, and similar) are removed from
structured context even when prefixed or written as headers, such as
`binance_api_key`, `client_secret`, or `X-MBX-APIKEY`. Free text is scrubbed for
`key=value`, `key: value`, quoted/JSON, camel-case, and `Bearer` forms, replacing
the value with `[REDACTED]`. Failure logs use the same sanitizer; credential-like
values recognized by it do not reach the alert, the email, or those log entries.

## Inbox

The inbox lives at `GET /alerts` (`alerts.index`) and requires an authenticated,
verified user. It opens in the **Grouped** view by default.

- The grouped view covers the last 30 days and groups alerts by type and bot,
  newest group first, 20 groups per page.
- A group row shows the highest severity, type, bot link, latest message and
  timestamp, total count, and unread count. Account-level groups show “No bot”;
  archived bot names are plain text instead of links.
- Expanding a group shows its five most recent occurrences. **View all** opens
  the same group in the ungrouped All alerts view with the bot filter preserved.
  Archived bot groups do not offer View all because archived bot filters are
  rejected.
- `POST /alerts/groups/read` marks only the unread alerts in one type and bot
  group through the group's latest timestamp. Later alerts stay unread.
- **All alerts** keeps the ungrouped view: newest first, 50 per page, with
  severity, title, message, bot link, timestamp, and read state.
- Both views honor the optional `bot_id` filter and reject another user's bot id
  or an archived bot id with 404.
- `POST /alerts/{alert}/read` marks one alert read.
- `POST /alerts/read-all` marks all visible unread alerts read, honoring the active
  bot filter.
- The shared `auth.unread_alerts` count drives the badge in the desktop and
  responsive navigation.

Feature tests: `tests/Feature/AlertInboxTest.php`,
`tests/Feature/AlertGroupedInboxTest.php`,
`tests/Feature/AlertIntegrationTest.php`. Unit coverage:
`tests/Unit/AlertGroupingTest.php`.

## Configuration

| Key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `alerts.enabled` | `ALERTS_ENABLED` | `true` | Master switch for alert creation. When false, risk events and activity logs are still recorded. |
| `alerts.dedupe_window_seconds` | `ALERTS_DEDUPE_WINDOW_SECONDS` | `900` | Length of the deduplication bucket. |
| `alerts.retention_days` | `ALERTS_RETENTION_DAYS` | `90` | Age at which `alerts:prune` removes an alert. |
| `alerts.incidents` | — | see catalog above | Covered types and their severity/title. Edit this map to add or remove a covered incident. |
| `alerts.engine_ingest_enabled` | `ALERTS_ENGINE_INGEST_ENABLED` | `true` | Enables the engine-event scanner and heartbeat check. The schedule remains registered and exits immediately when false. |
| `alerts.engine_ingest_batch` | `ALERTS_ENGINE_INGEST_BATCH` | `100` | Maximum rows read per source table per scanner run. |

Values are read with `env()` and are clamped to at least 1 second/day.

## Retention

`php artisan alerts:prune` deletes alerts older than the retention window. It runs
daily at 03:00 with `withoutOverlapping`. Use `--days=N` to override the configured
window for one run.

Only rows in `alerts` are deleted. The source `risk_events` and `activity_logs`
records are audit data and are never pruned by this command.

## Test map

| Flow | Test |
| --- | --- |
| Model, casts, relations | `AlertModelTest`, `AlertRelationshipsTest` |
| Archive retention | `BotArchiveTest` |
| Catalog and defaults | `AlertConfigTest` |
| Creation and dedupe | `AlertRecorderTest` |
| Secret redaction | `AlertSanitizerTest` |
| Email rendering and job | `AlertDeliveryTest` |
| Inbox, filters, read state, badge | `AlertInboxTest` |
| Retention command | `AlertRetentionTest` |
| End-to-end daily loss and kill switch | `AlertIntegrationTest` |
| Engine event ingestion, heartbeat, duplicate check | `AlertIngestionTest` |
| Protective exits | `ExitGuardAlertTest` |
