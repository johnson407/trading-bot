# Tasks

## 1. Schema and model

- [x] 1.1 Add an additive migration for nullable `stop_loss_percent`, `take_profit_percent`, `range_low`, and `range_high` columns on `bots`, with matching down-migration drops; verify `php artisan migrate` and `php artisan migrate:rollback --step=1` both succeed.
- [x] 1.2 Add the four fields to `Bot` fillable/casts and its docblock, expose them through `BotSummaryResource`, and add BotFactory states for configured and disabled rules; verify model casting and resource output with `tests/Feature/BotExitGuardModelTest.php`.

## 2. Rule evaluation and runtime enforcement

- [x] 2.1 Add `ExitRule` cases, `ExitSignal`, and a pure `ExitRules` evaluator for stop-loss, take-profit, and range thresholds with precedence stop-loss > take-profit > range; verify thresholds, boundaries, disabled rules, zero inventory, missing average entry, single-bound ranges, and precedence in `tests/Unit/ExitRulesTest.php`.
- [x] 2.2 Let `CancelBotOrders::handle` accept optional failure type/message defaults while preserving existing callers, and add `ExitGuardMonitor` to evaluate, lock the bot, pause once, cancel orders with `risk.exit_cancel_failed`, and record the trigger plus `bot.exit_guard_triggered`; verify pause/cancel, idempotency, cancellation failure, and unchanged inventory in `tests/Feature/ExitGuardMonitorTest.php`.
- [x] 2.3 Call the monitor from `BotTickPipeline` after the mark is persisted and before the daily-loss check and strategy tick; verify a triggered bot never runs its strategy or places an order, a paused bot does not retrigger, and an explicit resume while still beyond the threshold pauses again in `tests/Feature/ExitGuardPipelineTest.php`.
- [x] 2.4 Add `risk.stop_loss_triggered` (critical), `risk.take_profit_triggered` (warning), `risk.range_exit_triggered` (warning), and `risk.exit_cancel_failed` (critical) to `config/alerts.php`; verify the catalog and owner-scoped alerts in `tests/Feature/AlertConfigTest.php` and `tests/Feature/ExitGuardAlertTest.php`.
- [x] 2.5 Document the rule semantics, precedence, pause/cancel behavior, and no-liquidation guarantee in `docs/risk.md`, and the four bot fields in `docs/bot-parameters.md`; verify the documented names and defaults match the migration and config.

## 3. Dashboard configuration

- [x] 3.1 Add `BotExitGuardController`, a form request, and `PATCH /bots/{bot}/exit-guard`; accept blanks as null, validate percentages and range ordering, authorize ownership, lock the row, and record `bot.exit_guard_updated`; verify success, blank-disables, invalid input, running-bot updates, ownership, and activity in `tests/Feature/BotExitGuardTest.php`.
- [x] 3.2 Pass the current rules to `Bots/Show.vue` and add an exit-guard card that posts to the new route in every bot state; verify the Inertia props and rendered card in the dashboard test and confirm `npm run build` includes the page.
- [x] 3.3 Document the bot-detail exit-guard card and its validation in `docs/dashboard.md`; verify the documented route and fields match `routes/web.php` and the request rules.

## 4. Backtesting parity

- [x] 4.1 Snapshot the bot's exit rules into `BacktestConfiguration` through `BacktestSnapshotBuilder`, keeping the snapshot immutable after the bot changes; verify the stored and hydrated rules in the backtest configuration test.
- [x] 4.2 Evaluate the snapshotted `ExitRules` at each candle close before the daily-loss check, cancel simulated orders on a trigger, add `StopLoss`, `TakeProfit`, and `RangeExit` to `BacktestEndReason`, and record the matching outcome; verify each trigger, precedence, disabled rules, and unaffected range/risk-pause outcomes in `tests/Feature/BacktestExitGuardTest.php`.
- [x] 4.3 Display the three new outcomes on the backtest detail page and cover them in the backtest dashboard test; verify the rendered labels and stored `end_reason` values.
- [x] 4.4 Document snapshotting, close-only exit timing, and the new outcomes in `docs/backtesting.md`; verify the documented outcome table matches `BacktestEndReason`.

## 5. Integration and verification

- [x] 5.1 Add an end-to-end feature test that a stop-loss tick pauses the bot, cancels its open orders, leaves inventory unchanged, writes one owner alert, and shows it in the inbox; verify the alert is visible and unread.
- [x] 5.2 Update `docs/next-features.md` to mark protective exits shipped and make live equity analytics the next recommendation; verify the gaps, ranking, and hook points no longer describe the exit guard as missing.
- [x] 5.3 Run the full suite with `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync app composer test` and confirm zero failures.
- [x] 5.4 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `docker compose exec app npm run build`, and `openspec validate bot-exit-guard --strict`; confirm no PHPStan errors, a successful build, and a valid change.

## Workflow follow-up

- Request code review after the tracked tasks are complete.
- Archive the change after review and verification.
- Push the archived change.
