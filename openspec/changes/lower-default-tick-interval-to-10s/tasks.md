# Tasks

## 1. Default, test environment, and migration

- [x] 1.1 Change `trading.tick.interval_seconds` in `config/trading.php` and the `TRADING_TICK_INTERVAL` test override in `phpunit.xml` from 60 to 10; verify `config('trading.tick.interval_seconds')` returns 10 in a test run.
- [x] 1.2 Change the Go fallback in `engine/internal/config/config.go` from `"60"` to `"10"`, update the default expectation in `engine/internal/config/config_test.go` (stale-after becomes 15s), and verify `cd engine && go test ./internal/config/`.
- [x] 1.3 Change the `TRADING_TICK_INTERVAL` defaults in `docker-compose.yml`, `docker-compose.prod.yml`, and `.env.example` to 10; verify no `TRADING_TICK_INTERVAL` default of 60 remains.
- [x] 1.4 Add a forward-only migration that sets the `bots.tick_interval_seconds` column default to 10 and updates rows where the value is exactly 60; its `down` restores the column default to 60 only. Update the existing `BotTickIntervalMigrationTest` default assertion to 10 and add a feature test for the new migration (rewrites 60, preserves another value, checks the column default); verify both tests pass.
- [x] 1.5 Update `BotCreationTest` expectations to 10 and keep a case proving a configured override still wins; verify both tests pass.
- [x] 1.6 Update `docs/exchanges.md`, `docs/bot-parameters.md`, `docs/runtime.md`, `docs/engine.md`, `docs/deployment.md` (including the log-volume estimate and the pinned-`.env` operator step), `README.md`, and `engine/README.md` for the 10-second default; add a documented-default assertion to `ExchangeDocsTest`; verify the docs tests pass and no current doc still claims a 60-second default.

## 2. Read-only Settings readout

- [x] 2.1 Pass `max(1, (int) config('trading.tick.interval_seconds'))` from `SettingsController::index()` and render a read-only card in `Settings/Index.vue` stating that new bots use that cadence and individual bots can override it; verify the page builds and renders.
- [x] 2.2 Extend `SettingsUiTest` to assert the prop value and the readout wording, and verify the test passes.
- [x] 2.3 Update the Settings section and the Edit section of `docs/dashboard.md`: add tick interval to the editable-field list, and state that name-only, fee-only, and interval-only changes do not request a reconcile while the separate stale check may still pause an overdue bot; verify `DashboardDocsTest` still passes and grep the new editable-field and interval-only/stale-caveat sentences so the task cannot pass on the old prose.

## 3. Per-bot interval editing

- [x] 3.1 Expose `tick_interval_seconds` in `BotSummaryResource` and verify an Inertia bot detail or edit response contains the stored value.
- [x] 3.2 Add `tick_interval_seconds => ['sometimes', 'integer', 'min:1', 'max:3600']` to `UpdateBotRequest` and verify `BotUpdateTest` cases reject 0, 3601, and a non-integer while accepting an omitted field.
- [x] 3.3 Include the interval in both the `$proposed` diff input and the `$attributes` written by `$locked->update()` in `BotController::update()`, and add a `BotEditDiff::FIELDS` entry; verify a `BotUpdateTest` case persists the new value and records it in the activity diff, publishes no `bot.config` for an interval-only change when nothing is pending, and still publishes an already-pending reconcile.
- [x] 3.4 Add the tick interval input to `resources/js/Pages/Bots/Edit.vue` bound to `props.bot.tick_interval_seconds` with `min="1"`, `max="3600"`, and error display; add it to `previewPayload()` and the preview `watch` list, include it in `PreviewBotRequest::proposedEdit()` with a fallback to the bot's stored value, add `tick_interval_seconds => ['sometimes', 'integer', 'min:1', 'max:3600']` to `PreviewBotRequest::rules()`, and verify a `BotPreviewTest` case returns a `tick_interval_seconds` diff, an invalid previewed value is rejected, and `npm run build` succeeds.
- [x] 3.5 Verify a running paper interval-only save still requires `confirm_running_edit` and that live running and paused bots remain non-editable, using `BotUpdateTest` cases.
- [x] 3.6 Update the running-paper banner and checkbox copy in `Edit.vue` so they no longer promise cancellation for every save, update the pinned strings in `BotEditUiTest`, and verify the test passes.
- [x] 3.7 Update the per-bot parameter documentation in `docs/bot-parameters.md` so the interval is described as a creation default that is editable per bot after creation, and add it to the edit-form field list (currently around line 280), which omits it today; verify the file no longer lists it only as a runtime env value.
- [x] 3.8 Add a Go runtime test that updates a running bot's `tick_interval_seconds` in Postgres and asserts `runDue` selects it at the new interval on the next scheduler pass without a restart or command; verify `cd engine && go test ./internal/runtime/`.
- [x] 3.9 Add a Go runtime test for the stale transition: a running paper bot with a 3600-second interval and a last successful tick 300 seconds old is lowered to 10 seconds, and the stale check pauses it (`risk.stale_tick`) and cancels open orders rather than reconciling; verify `cd engine && go test ./internal/runtime/`.

## 4. Integration verification

- [x] 4.1 Run the full PHP suite, PHPStan, Pint, the frontend build and JS tests, and the full engine suite (`cd engine && go test ./...`); verify all pass.
- [x] 4.2 Run `openspec validate lower-default-tick-interval-to-10s --strict` and verify it passes.

## Workflow follow-up

- Archive the change with `openspec archive lower-default-tick-interval-to-10s` after implementation, verification, and merge.
