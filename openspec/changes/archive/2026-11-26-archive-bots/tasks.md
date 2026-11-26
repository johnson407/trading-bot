# Tasks

## 1. Persistence and history

- [x] 1.1 Add a migration that adds a nullable indexed `deleted_at` timestamp to `bots` and drops it on rollback. Verify with a fresh `migrate --force` plus a `Schema::hasColumn('bots', 'deleted_at')` check.
- [x] 1.2 Add `SoftDeletes` to `App\Models\Bot`, document `deleted_at` on the model, and add `->withTrashed()` to the `bot()` relations on `ActivityLog`, `Alert`, `BacktestRun`, and `BacktestSweep`. Verify an archived bot still resolves through each relation and the relation query does not leak into active listings.
- [x] 1.3 Update the permanent-deletion tests that currently call `$bot->delete()`: `AlertRelationshipsTest`, `BacktestDashboardTest`, and `BacktestSweepModelTest` must use `forceDelete()` for the hard-delete cases, with separate assertions that archive retains the association and name.
- [x] 1.4 Add `bot.archived` and `bot.restored` to `ActivityRecorder::USER_TYPES`. Verify `ActivityTypeBoundaryTest` passes and each new type can be recorded for a bot.

## 2. Runtime and control safety

- [x] 2.1 Replace the model-instance `desired_status` writes in `app/Actions/StartBot.php`, `PauseBot.php`, and `StopBot.php` with soft-delete-scoped conditional updates. Abort without publishing or recording activity when the update affects no row. Apply the same guard to per-bot writes in `ActivateKillSwitch`. Add regression coverage that passes a stale model loaded before archive and asserts no desired-status change and no published command.
- [x] 2.2 Add `deleted_at IS NULL` guards to the Go engine's bot loading, status update in `applyStatus`, `reconcile`, due-check, stale-check, and live-order sync queries. Leave the account-wide daily-loss queries including all bots, and add a comment explaining why. Add Go runtime tests for stale start/pause/stop, reconciliation, stale checks, and archived balances in account loss.
- [x] 2.3 Add a `restore` ability to `BotPolicy` (owner only) and extend `AuthorizationTest` to assert owner/non-owner behavior for both `delete` and `restore`.

## 3. Archive and restore backend

- [x] 3.1 Add `DELETE /bots/{bot}` (`bots.destroy`, `throttle:bot-control`) and `POST /bots/{bot}/restore` (`bots.restore`, trashed binding, `throttle:bot-control`). In `destroy`, lock the bot, reject unless `status` and `desired_status` are both `stopped`, no `pending`/`open`/`partially_filled` order exists, and a live bot has a fresh `EngineHeartbeat`; then soft-delete and record `bot.archived`. In `restore`, abort 404 unless the bound bot is trashed, restore it, and record `bot.restored`.
- [x] 3.2 Add the `?archived=1` filter to `BotController::index` and the server-computed `canArchive` prop to `BotController::show`, using the loaded open-order collection rather than `BotSummaryResource.open_orders_count`. Include `canArchive` in the Show page's poll `only` list.
- [x] 3.3 Update `DashboardController` to count active bots only but aggregate realized/unrealized P&L across `Bot::withTrashed()`. Add an `archived` flag to dashboard activity payloads, including `deleted_at` in the eager-loaded bot selection.
- [x] 3.4 Update `EquitySnapshotter::capture()` to include archived bots in both `whereHas` and `with`, so an all-archived user is still captured and archived inventory/P&L remains in the snapshot. Update `docs/analytics.md` and its tests.
- [x] 3.5 Add an `archived` flag to alert payloads and eager-load `deleted_at`; the alert bot filter stays active-only.
- [x] 3.6 Add `tests/Feature/BotArchiveTest.php` covering:
  - eligible paper archive hides the bot from the active list, archived view includes it, and activity records `bot.archived`;
  - running, paused, error, desired-status mismatch, and open-order bots are rejected;
  - a live bot without a fresh heartbeat is rejected;
  - non-owner archive and restore are denied;
  - restore returns the bot to the active list still stopped, publishes no start command, and records `bot.restored`;
  - restore of a non-archived bot is rejected;
  - archived `show`, `edit`, `activity`, control, order-cancel, and exit-guard routes are 404;
  - backtest and sweep create selectors exclude archived bots, and their store endpoints reject an archived `bot_id`;
  - alert filter and kill switch exclude archived bots;
  - dashboard counts exclude archived bots while aggregate P&L includes them;
  - equity capture includes an all-archived user and archived residual inventory;
  - activity, alert, backtest, and sweep references still show the archived bot's name;
  - dashboard and alert payloads mark archived bot references for plain-text rendering.
- [x] 3.7 Update `BotListTest`, `BotDetailTest`, `AuthorizationTest`, `AlertRelationshipsTest`, `BacktestDashboardTest`, `BacktestSweepModelTest`, dashboard/analytics tests, and docs tests for the new behavior.

## 4. UI and documentation

- [x] 4.1 Add the Active/Archived toggle, archived-specific empty state, and per-row Restore action to `resources/js/Pages/Bots/Index.vue`; in archived mode render the name without a detail link and hide Create/Kill switch.
- [x] 4.2 Add the confirmed Archive action to `resources/js/Pages/Bots/Show.vue`, shown only when `canArchive` is true, warning that residual inventory is not sold, and surface rejected archives through the existing `action` error slot.
- [x] 4.3 Update `Dashboard.vue` and `Alerts/Index.vue` to render archived bot references as plain text instead of `bots.show` links.
- [x] 4.4 Update `docs/dashboard.md` with the archive/restore behavior, archived links, `bots.destroy`/`bots.restore`, and `BotArchiveTest` in the test map; update `DashboardDocsTest`'s route and test-name lists.
- [x] 4.5 Update `docs/alerts.md` and `docs/backtesting.md` to distinguish archive (association and name retained) from permanent deletion (association cleared); update `docs/security.md` for the restore ability and `docs/risk.md` for archived bots being outside per-bot risk evaluation but inside account daily-loss totals.

## 5. Integration verification

- [x] 5.1 Run the full Pest suite with the documented Docker environment overrides and confirm every test passes.
- [x] 5.2 Run the Go engine tests, including the new archived-bot runtime cases.
- [x] 5.3 Run `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `vendor/bin/pint --dirty`, `npm run build`, and `npm run test:js`; confirm all pass.
- [x] 5.4 Run `openspec validate archive-bots --strict`, then inspect `git status --short` and `git diff --check` to confirm the intended Laravel, engine, Vue, docs, and test files changed and no unrelated files did.

## Workflow follow-up

- Archive the change after implementation and review pass.
- Re-run `openspec validate` after archive and confirm the `bot-lifecycle` main spec exists with the archive/restore requirements.
