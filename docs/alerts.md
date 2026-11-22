# Alerts

Alerts turn operational incidents into something the owner actually sees. The
system already records risk events and activity logs; alerts are the durable,
owner-scoped notification layer on top of them.

## What creates an alert

Alert creation is hooked into `RiskEventRecorder` and `ActivityRecorder`. Only the
types listed in `config/alerts.php` produce an alert. Every other risk event or
activity entry is still written to its original table and remains visible in the
bot activity feed.

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

Cancellation failures are critical because they leave orders live after the bot
has been paused.

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
(activity plus risk event) collapses to one alert for the same reason.

Each alert keeps a polymorphic `source_type`/`source_id` pointing at the risk
event or activity log that produced it. Deleting a bot sets the alert's `bot_id`
to null; the alert itself is retained.

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
verified user.

- Alerts are owner-scoped and shown newest first, 50 per page.
- Each row shows severity, title, message, bot link when present, timestamp, and
  read state.
- An optional `bot_id` filter narrows the list to one owned bot. Another user's bot
  id returns 404.
- `POST /alerts/{alert}/read` marks one alert read.
- `POST /alerts/read-all` marks all visible unread alerts read, honoring the active
  bot filter.
- The shared `auth.unread_alerts` count drives the badge in the desktop and
  responsive navigation.

Feature tests: `tests/Feature/AlertInboxTest.php`,
`tests/Feature/AlertIntegrationTest.php`.

## Configuration

| Key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `alerts.enabled` | `ALERTS_ENABLED` | `true` | Master switch for alert creation. When false, risk events and activity logs are still recorded. |
| `alerts.dedupe_window_seconds` | `ALERTS_DEDUPE_WINDOW_SECONDS` | `900` | Length of the deduplication bucket. |
| `alerts.retention_days` | `ALERTS_RETENTION_DAYS` | `90` | Age at which `alerts:prune` removes an alert. |
| `alerts.incidents` | — | see catalog above | Covered types and their severity/title. Edit this map to add or remove a covered incident. |

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
| Catalog and defaults | `AlertConfigTest` |
| Creation and dedupe | `AlertRecorderTest` |
| Secret redaction | `AlertSanitizerTest` |
| Email rendering and job | `AlertDeliveryTest` |
| Inbox, filters, read state, badge | `AlertInboxTest` |
| Retention command | `AlertRetentionTest` |
| End-to-end daily loss and kill switch | `AlertIntegrationTest` |
