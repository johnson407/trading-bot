# Design

## Context

See `proposal.md` — Why. The relevant current state:

- `RiskEventRecorder` and `ActivityRecorder` are the two write choke points for incidents. Every pause path already calls at least one of them (`DailyLossMonitor`, `ConsecutiveFailureTracker`, `BotHealthMonitor`, `BotReconciler`, `ActivateKillSwitch`, and the cancellation-failure branches in `BotTickPipeline` / `CancelBotOrders`).
- `User` already uses `Notifiable`, but the project has no notification classes, no notification table, and no delivery path.
- Trading pauses and control actions may run inside database transactions; order placement is explicitly transactional. External delivery must not race an uncommitted alert or run inside the trade transaction.
- Queue is Redis/Horizon. Tests use PostgreSQL, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, and `CACHE_STORE=array`.
- The UI is Inertia 2 + Vue 3 with shared props in `HandleInertiaRequests` and a single `AuthenticatedLayout`.

## Goals / Non-Goals

**Goals:**

- Create one durable, owner-scoped alert per covered incident, linked to its source record.
- Keep alert creation inside the existing recorder flow so no pause path is missed.
- Deliver in-app immediately and email only after commit, without affecting trading.
- Suppress duplicate alerts per owner, bot, and incident type in a fixed window.
- Provide an authenticated inbox with unread count, bot filter, and read actions.
- Prune alerts on a schedule without touching the audit trail.

**Non-Goals:**

- SMS, push, Slack, or generic webhooks (the later API/webhooks candidate).
- Per-user channel preferences, quiet hours, or escalation policies.
- Alerting on every risk or activity row, including routine exposure blocks and inventory deferrals.
- Historical backfill of incidents that happened before this change.
- Stop-loss, take-profit, or any change to trading decisions.
- A resend UI or delivery-status tracking in v1.

## Decisions

### D1: A dedicated `alerts` table, not Laravel's `notifications` table

Alerts need first-class columns for `bot_id`, `type`, `severity`, `source`, `read_at`, and dedupe. Laravel's `notifications` table stores payload in JSON and is designed for channel fan-out, which would force JSON filtering for the inbox and dedupe. A dedicated table keeps the inbox queries, bot filter, dedupe index, and retention simple, and matches the project's existing recorder style. `User` keeps `Notifiable` for framework mail (email verification); alert email uses a queued mailable instead of the notification channel.

Schema:

| Column | Notes |
| --- | --- |
| `id` | bigint primary key |
| `user_id` | FK to users, cascade on delete |
| `bot_id` | nullable FK to bots, null on delete |
| `type` | source incident type, e.g. `risk.daily_loss_limit` |
| `severity` | `critical` or `warning` |
| `title` | short human label from the incident catalog |
| `message` | source message |
| `context` | JSON observed values |
| `source_type`, `source_id` | nullable morph to `RiskEvent` or `ActivityLog` |
| `dedupe_key` | hash of owner + bot + type |
| `dedupe_bucket` | fixed-window bucket, see D3 |
| `read_at` | nullable timestamp |
| `created_at` | timestamp |

Indexes: unique `(dedupe_key, dedupe_bucket)`; `(user_id, created_at)`; `(user_id, read_at)`; `(bot_id, created_at)`; `(source_type, source_id)`.

Alternative rejected: `DatabaseNotification` plus a custom `AlertNotification`. It is idiomatic but makes the inbox filter, source link, severity, and dedupe queries JSON-dependent, and adds a second read-state model to maintain.

### D2: Hook alert creation into the two existing recorders via a config catalog

`AlertRecorder` is injected into `RiskEventRecorder` and `ActivityRecorder`. After the source row is created, the recorder calls `AlertRecorder::fromRiskEvent($event)` or `fromActivity($event)`, which:

1. Looks up the event type in `config('alerts.incidents')`.
2. Skips types not in the catalog.
3. Uses the catalog severity and title. The catalog is authoritative so every path that emits the same incident type produces the same alert, regardless of the source severity.
4. Creates the alert with a source morph and dispatches delivery on creation.

The catalog is the single place that decides what is alert-worthy. Default entries:

| Source type | Severity | Reason |
| --- | --- | --- |
| `risk.daily_loss_limit` | critical | bot paused, capital threshold reached |
| `exchange.failure_threshold` | critical | bot paused after repeated failures |
| `risk.reconciliation_conflict` | critical | bot paused, local state diverged |
| `risk.live_trading_disabled` | critical | live bot paused by the configuration gate |
| `risk.stale_bot` | warning | bot paused because the tick loop stopped |
| `kill_switch.activated` | critical | account-wide halt |
| `risk.daily_loss_cancel_failed` | critical | orders remain after a protective pause |
| `risk.kill_switch_cancel_failed` | critical | orders remain after the kill-switch |
| `risk.strategy_stop_cancel_failed` | critical | orders remain after a strategy stop |
| `risk.live_cancel_failed` | critical | orders remain after the live gate paused the bot |
| `risk.failure_cancel_failed` | critical | orders remain after the consecutive-failure pause |

Not in the catalog: `risk.position_limit`, `order.min_amount`, `order.min_notional`, `order.level_missing`, `risk.insufficient_inventory`, and every routine order/fill/balance event. These are either expected at a cap or already visible in the activity log.

`exchange.failure_threshold` is written to both logs with the same type. Both calls produce the same dedupe key and bucket, so the second is suppressed; the alert points at whichever source row was written first.

Alternatives rejected:

- Calling `AlertRecorder` at each pause site: easy to miss a new pause path and duplicates the mapping logic.
- Eloquent model events on `RiskEvent` / `ActivityLog`: implicit, fires for every row, and harder to test than the explicit recorders.

### D3: Dedupe with a tumbling bucket and a unique index

`dedupe_key = hash('sha256', "user:{$userId}|bot:{$botId ?? 'account'}|type:{$type}")`.

`dedupe_bucket = intdiv(now()->getTimestamp(), max(1, config('alerts.dedupe_window_seconds', 900)))`.

`Alert::createOrFirst(['dedupe_key' => ..., 'dedupe_bucket' => ...], [...])` uses Laravel's savepoint-aware create, so a concurrent insert hits the unique index and the second caller receives the existing row. Delivery is dispatched only when `wasRecentlyCreated` is true. This is durable, needs no cache, and rolls back cleanly with the surrounding transaction: if the pause rolls back, the alert row rolls back and a later attempt can create a fresh one.

The insert runs inside its own `DB::transaction`, which becomes a savepoint when the caller is already in a transaction. Any sanitizer or insert failure is caught, logged without secret values, and returns `null`; it cannot abort the pause or tick that emitted the source event. The source risk event or activity log remains committed by the caller's transaction.

Trade-off: the window is tumbling, not sliding, so two incidents on opposite sides of a bucket boundary can both alert. For anti-spam that is acceptable; a sliding window would need a lock/dedupe table or a cache key and would lose correctness on rollback or cache eviction. The window is configurable (default 15 minutes) if operators want a stricter or looser cadence.

### D4: Dispatch email after commit, through a queued job

When the alert is newly created and critical, `AlertRecorder` registers `DB::afterCommit(fn () => SendAlertEmail::dispatch($alert->id))`. Outside a transaction Laravel runs the callback immediately; inside one it waits for the outermost commit. The job loads the alert, sends `AlertMail` to the owner, and implements three tries with backoff. Its `failed()` method logs the alert id and error. A missing alert (retention raced the job) is treated as a no-op.

In-app delivery is the alert row itself, so it is available as soon as the transaction commits and does not depend on the queue. If the queue or mail transport is down, or the alert row cannot be stored, the failure is logged, the alert remains in the inbox when stored, and the pause or control action is never rolled back or delayed.

Testing note: `RefreshDatabase` wraps each test in a transaction, so `DB::afterCommit` callbacks do not run in those tests. Delivery-boundary tests use `DatabaseMigrations` (or invoke `SendAlertEmail` directly) and assert with `Queue::fake()` / `Mail::fake()`; the rest of the suite asserts the durable alert row and the job's behavior in isolation.

Alternative rejected: dispatching the job eagerly. A Redis worker could pick it up before the alert row commits and find nothing; it would either fail or send stale data.

### D5: Inbox routes, shared unread count, and Vue page

- `GET /alerts` → `AlertController@index`, paginated 50, optional `bot_id` filter. The bot filter is validated against the authenticated user's bots.
- `POST /alerts/{alert}/read` → owner-scoped find, sets `read_at`, redirects back.
- `POST /alerts/read-all` → marks all unread (or all unread for the selected bot) as read.
- Routes live in the existing `auth` + `verified` group. Ownership is enforced in the controller query, not left to route binding.
- `HandleInertiaRequests` shares a lazy `auth.unread_alerts` count; the layout renders a badge on desktop and responsive nav.
- `Alerts/Index.vue` follows the existing table styling: severity badge, bot link, message, timestamp, read state, per-row "Mark read", a bot filter, and a "Mark all read" action. A `read_at` value renders the row in a muted style.

### D6: Retention command and schedule

`alerts:prune` deletes alerts older than `config('alerts.retention_days', 90)` and reports the count. It is scheduled daily at 03:00 with `withoutOverlapping`. It only deletes `alerts`; `risk_events` and `activity_logs` are untouched. The email job tolerates a missing alert.

### D7: Configuration

`config/alerts.php` holds:

- `enabled` (default true) — an operational kill switch for alert creation.
- `dedupe_window_seconds` (default 900).
- `retention_days` (default 90).
- `incidents` — the source-type catalog with severity and title.

Environment overrides: `ALERTS_ENABLED`, `ALERTS_DEDUPE_WINDOW_SECONDS`, `ALERTS_RETENTION_DAYS`. Mail transport stays deployment-configured; the default `MAIL_MAILER=log` means email is written to the log until a real transport is configured, while the inbox still works.

## Risks / Trade-offs

- **Email is only as good as the mail configuration.** → The inbox is the durable channel; document that `MAIL_MAILER=log` is not a real notification. The unread badge keeps the inbox visible.
- **A deduped incident hides a second, distinct occurrence of the same type on the same bot.** → The first alert already told the user the bot is paused; repeated emails add no new decision. Different bots and different types are never collapsed. The window is configurable.
- **Tumbling bucket boundary allows two alerts close together.** → Accepted for anti-spam simplicity; documented in D3.
- **Recorder coupling puts alert creation on the trading path.** → Only the alert insert is synchronous and shares the database transaction. Catalog lookup is config-only, email is after-commit and queued, and queue/mail failures cannot escape the job. A database failure would already fail the surrounding transaction.
- **Alert context could grow or contain noisy error text.** → Follow the existing recorder convention of small, structured context; the spec limits it to rule, observed values, limits, and non-secret errors.
- **Alert volume from repeated failures.** → Dedupe plus a curated catalog; position-limit and min-notional blocks are deliberately excluded.
- **`exchange.failure_threshold` writes two source rows.** → Same type and key; the unique dedupe index collapses them into one alert.

## Migration Plan

1. Add the `alerts` table migration and `config/alerts.php`.
2. Add `Alert` model, `AlertRecorder`, incident catalog, mailable, and queued job.
3. Inject `AlertRecorder` into `RiskEventRecorder` and `ActivityRecorder` and wire the catalog.
4. Add controller, routes, shared unread prop, navigation badge, and the Vue inbox page.
5. Add `alerts:prune` and schedule it.
6. Run migrations and the focused test suite.

Deployment notes:

- No existing tables change; the migration is additive.
- Historical incidents are not backfilled; alerting begins with the next covered incident.
- A queue worker is required for email, not for the inbox.
- Rollback: set `ALERTS_ENABLED=false` to stop new alerts without reverting code, or roll back the additive migration; trading behavior is unaffected either way.
