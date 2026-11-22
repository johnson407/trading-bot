# Tasks

## 1. Schema and configuration

- [x] 1.1 Create the `alerts` table migration with owner/bot/type/severity/content/context/source/read/dedupe columns and the indexes from design D1; verify with `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test app php artisan migrate` and a rollback/re-migrate cycle.
- [x] 1.2 Add `App\Models\Alert` with casts, `user`/`bot`/`source` relations, `unread` scope, and a factory; verify a model test covers fillable fields, JSON context, date casts, and owner/bot relations.
- [x] 1.3 Add `config/alerts.php` with `enabled`, `dedupe_window_seconds`, `retention_days`, and the incident catalog, plus `ALERTS_*` entries in `.env.example`; verify a config test asserts the defaults and every catalog entry has a severity and title.
- [x] 1.4 Add `alerts()` and `unreadAlerts()` relations to `User` and `alerts()` to `Bot`; verify a relationship test covers both directions and that deleting a bot nulls `bot_id` without deleting the alert.
- [x] 1.5 Create `docs/alerts.md` with the configuration keys, defaults, and environment overrides; verify the file names every key added in 1.3 and matches `.env.example`.

## 2. Alert creation and deduplication

- [x] 2.1 Implement `AlertRecorder` with catalog lookup, account-vs-bot dedupe key, tumbling bucket, savepoint-aware `createOrFirst`, catalog-authoritative severity/title, source morph, and isolated persistence that logs and returns null on failure; verify unit tests cover creation, non-catalog skip, disabled config, same-bucket suppression, new-bucket creation, the null-bot key, and a failed insert not aborting the caller's transaction.
- [x] 2.2 Inject `AlertRecorder` into `RiskEventRecorder` and create an alert after each covered risk event; verify feature tests show `risk.daily_loss_limit` creates one alert while `risk.position_limit` and `risk.insufficient_inventory` create none.
- [x] 2.3 Inject `AlertRecorder` into `ActivityRecorder` and create an alert after each covered activity; verify tests show `kill_switch.activated` creates an account alert (no `bot_id`), `bot.started` creates none, and the activity/risk pair for `exchange.failure_threshold` leaves exactly one alert.
- [x] 2.4 Add the covered-incident and deduplication sections to `docs/alerts.md`; verify the document lists every catalog type, the intentionally excluded types, and the 15-minute default window.

## 3. Alert delivery

- [x] 3.1 Add the `AlertMail` mailable and its view with title, bot name when present, message, observed context, timestamp, and an inbox link; verify a mailable test renders it and asserts no credential fields or secret values appear.
- [x] 3.2 Add the queued `SendAlertEmail` job with three tries, backoff, a `failed()` log entry, and a missing-alert no-op; verify job tests with `Mail::fake()` cover success, a deleted alert, and a transport exception that is logged without changing the alert.
- [x] 3.3 Dispatch `SendAlertEmail` through `DB::afterCommit` only for newly created critical alerts; verify a `DatabaseMigrations` test with `Queue::fake()` shows the job queued after commit, not before, and a rollback test shows no queued email.
- [x] 3.4 Add a delivery-isolation feature test in which the mail transport throws during a daily-loss pause; verify the bot remains paused, the alert remains in the inbox, and the failure is logged rather than rethrown into the pause path.
- [x] 3.5 Add the delivery-channels section to `docs/alerts.md`, including the queued email behavior and the fact that `MAIL_MAILER=log` does not reach a real inbox; verify the documented behavior matches the job and config.

## 4. Alert inbox and navigation

- [x] 4.1 Add `AlertController@index` with owner scoping, newest-first pagination, and a `bot_id` filter validated against the user's bots; verify feature tests cover listing, pagination, filter, and rejection of another user's bot id.
- [x] 4.2 Add `markRead` and `markAllRead` actions with owner scoping; verify tests cover marking one alert, marking all, marking a filtered bot's alerts, and a cross-user read attempt returning not found.
- [x] 4.3 Share a lazy `auth.unread_alerts` count from `HandleInertiaRequests`; verify an Inertia test asserts the count for a user with mixed read/unread alerts and zero for a user with none.
- [x] 4.4 Add the `Alerts/Index.vue` page with severity badges, bot links, timestamps, read styling, a bot filter, per-row and bulk read actions, and empty state; verify `npm run build` succeeds and a feature test renders the page with the expected props.
- [x] 4.5 Add Alerts links with the unread badge to both the desktop and responsive navigation; verify a feature test sees the link on an authenticated page and `npm run build` succeeds.
- [x] 4.6 Add the inbox-usage section to `docs/alerts.md` and mention Alerts in `docs/dashboard.md`; verify both documents describe the filter and read actions that exist in 4.1-4.5.

## 5. Retention

- [x] 5.1 Add the `alerts:prune` command with a `--days` option defaulting to `config('alerts.retention_days')`; verify a command test deletes only alerts older than the cutoff and leaves newer alerts, `risk_events`, and `activity_logs` untouched.
- [x] 5.2 Schedule `alerts:prune` daily at 03:00 with `withoutOverlapping`; verify `docker compose exec -u www-data app php artisan schedule:list` shows the command and cadence.
- [x] 5.3 Add the retention section to `docs/alerts.md`; verify it documents the 90-day default, the override, and that audit records are not pruned.

## 6. Integration verification

- [x] 6.1 Add an end-to-end feature test: a daily-loss breach pauses the bot, creates one owner-scoped alert, queues one critical email, shows the alert and unread badge in the inbox, and marks it read; verify the test passes.
- [x] 6.2 Add an end-to-end kill-switch test: activation creates one account-level alert with no bot, remains visible to the owner, and is hidden from another user; verify the test passes.
- [x] 6.3 Run the full suite with `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync app composer test`; verify all tests pass.
- [x] 6.4 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress` and `docker compose exec app npm run build`; verify both succeed.
- [x] 6.5 Run `openspec validate alerts-and-notifications --strict`; verify the change validates without warnings.

## Workflow follow-up

- Archive the change after review and a green suite.
- Re-check `docs/next-features.md`: alerts should move from open candidate to shipped, and the exit-guard candidate remains next.
