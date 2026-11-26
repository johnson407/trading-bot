# Design

## Context

See `proposal.md` for motivation and the spec deltas for required behavior.

`bots` currently has no removal path. `BotPolicy::delete` already exists but no route or controller action uses it. All active bot listings in Laravel go through `User::bots()` or `Bot::query()`. Historical records (`activity_logs`, `alerts`, `backtest_runs`, `backtest_sweeps`) reference `bots.id`, so deleting a bot row would either break those references or force a wide cascade.

The Go engine does not filter a `deleted_at` column. That matters beyond ticking: an in-flight `bot.start` command, reconciliation of a pending flag, or a stale control request could mutate an archived row. Archiving therefore needs both Laravel-side conditional writes and engine-side row filters.

## Goals / Non-Goals

**Goals:**

- Hide retired bots from every active Laravel listing and selector with one reliable mechanism.
- Keep every related row and foreign key intact, and keep archived bot names visible in history.
- Reject archive attempts that could race a running engine or rely on a stale engine heartbeat.
- Prevent stale start/pause/stop requests and engine commands from resurrecting or mutating archived bots.
- Keep archived balances in account financial totals without counting archived bots as active.
- Keep the change inside Laravel, the Go engine, Vue, and docs; no exchange or live-gate changes.

**Non-Goals:**

- Permanent deletion or data retention/purge jobs.
- Archiving a running bot automatically, or selling inventory as part of archiving.
- Changing how credentials, risk limits, or kill-switch rules work.
- Adding a separate archive page; the archived view is a filter on the existing bot list.
- Making archived bot detail pages reachable; dashboard and alert references degrade to plain text.

## Decisions

### 1. Use Eloquent soft deletes on `bots`

Add a nullable, indexed `deleted_at` timestamp with `SoftDeletes` on `App\Models\Bot`. A global scope then excludes archived bots from `User::bots()`, `Bot::query()`, dashboard counts, backtest/sweep create selectors, the alert bot filter, and the kill switch without touching each call site.

Alternative rejected: a custom `archived_at` column with hand-written scopes. It requires every current and future active-bot query to remember the scope; missing one leaks archived bots into backtest selectors or kill-switch targets. Soft deletes make the safe behavior the default.

Alternative rejected: hard delete with cascade or nulling. It loses exactly the history the archive is meant to preserve and creates FK-ordering complexity across eight tables.

### 2. Archive only when fully stopped, order-free, and live-heartbeat fresh

The archive action checks, inside a transaction with `lockForUpdate()`, that `status` and `desired_status` are both `stopped` and that no order is `pending`, `open`, or `partially_filled`. These are the same statuses the list already counts as open orders.

For a live bot, the action also requires a fresh `EngineHeartbeat`. A stopped live bot's local order table can be stale if the engine or exchange state has drifted; requiring a live heartbeat avoids archiving on the strength of a dead engine's last known state. Untracked exchange orders remain a documented limitation: the stop path already asks the engine to cancel open orders, and archiving is rejected while any local order is open.

This is stricter than "not running": a paused or error bot can still have open orders and may be resumed or reconciled by the engine. Requiring stopped matches the existing `Stop` action, which already cancels orders. The check is a controller precondition; the model is not given a hook that silently archives.

### 3. Make stale control requests unable to mutate an archived bot

Laravel's `StartBot`, `PauseBot`, and `StopBot` currently call `$bot->update(['desired_status' => ...])` on a model instance. Eloquent model updates use `newModelQuery()`, which bypasses the soft-deleting global scope, so a request that loaded the bot before an archive can write `desired_status` after the archive commits.

Change those writes to scoped conditional updates, for example `Bot::query()->whereKey($bot->id)->update([...])`, which apply `deleted_at IS NULL`. If the update affects zero rows, the action aborts without publishing a command or recording activity. The same rule applies to the per-bot writes in `ActivateKillSwitch`. This closes the race atomically: if archive commits first, the start update sees a trashed row and does nothing; if start commits first, archive's eligibility check sees the non-stopped desired status and rejects.

The engine is the second line of defense:

- `loadBot` and the status update in `applyStatus` add `deleted_at IS NULL`.
- `reconcile` adds `deleted_at IS NULL` so archived rows with `kill_switch_requested_at` or `cancel_retry_requested` are ignored.
- Due-check, stale-check, and live-order sync queries add `deleted_at IS NULL`.
- Account-wide daily-loss queries intentionally keep all of the user's bots. Archive requires a stopped bot, and stopped bots already do not tick; removing their balances at archive would make account risk drop discontinuously. Their realized P&L and inventory remain part of account exposure. Their own risk rules and protective exits do not run while archived.

### 4. Keep historical relations working with `withTrashed()`

Soft-deleting a bot would make `$activity->bot`, `$alert->bot`, `$backtestRun->bot`, and `$sweep->bot` return `null`. Add `->withTrashed()` to those four `belongsTo` relations so history keeps showing the bot name. This does not expose archived bots in active listings because those queries use the Bot model's global scope.

Alternative rejected: snapshotting the bot name into each historical row. That is a larger schema change and duplicates data that the FK already preserves.

### 5. Keep archived balances in financial totals

Soft deletes must not make account money disappear.

- `EquitySnapshotter::capture()` includes archived bots via `whereHas('bots', fn ($query) => $query->withTrashed())` and `with(['bots' => fn ($query) => $query->withTrashed()])`. A user whose bots are all archived is still captured. The snapshot's `bot_count` is the number of included bots, active plus archived.
- `DashboardController` keeps active-only counts but aggregates realized and unrealized P&L across all bots using `Bot::withTrashed()`.
- Archived bots use their last stored mark price, falling back to average entry, because the engine no longer updates their mark. This is the same value they contributed immediately before archive.

Operational surfaces stay active-only: bot list, selectors, alert filter, kill switch, and dashboard state counts.

### 6. Routes, controller, and payloads

- `DELETE /bots/{bot}` -> `bots.destroy` archives. The default implicit binding intentionally does not resolve trashed bots. Add `throttle:bot-control`.
- `POST /bots/{bot}/restore` -> `bots.restore` uses `->withTrashed()` binding and `throttle:bot-control`. It aborts 404 if the bound bot is not trashed, so a normal bot is not reported as restored.
- `GET /bots?archived=1` renders the same `Bots/Index` page with only trashed bots and an `archived` prop; with no flag it renders active bots only.
- `Bots/Show` receives a server-computed `canArchive` flag (`status === stopped`, `desired_status === stopped`, loaded open-order collection empty, and for live bots a fresh heartbeat). The UI does not infer eligibility from a possibly zero `open_orders_count`; add `canArchive` to the page's poll `only` list so the button tracks engine transitions.
- Archiving records `bot.archived`; restoring records `bot.restored`. Both are added to `ActivityRecorder::USER_TYPES`.
- `BotPolicy` gains a `restore` ability (owner only); archive uses the existing `delete` ability.
- Dashboard recent activity includes an `archived` flag for the referenced bot; archived names render as plain text. Alert payloads add the same flag and the alert inbox renders archived bot names without a `bots.show` link.
- Archived `bots.show`, `bots.edit`, `bots.activity`, `bots.exit-guard.update`, `bots.orders.cancel`, and control routes remain 404 through normal binding.

Redirects: a successful archive returns to the active list; a successful restore returns to the active list with the bot visible again. Ineligible archive attempts return a validation-style error on the detail page and leave the bot untouched.

### 7. UI shape

`Bots/Index.vue` gains an **Archived** / **Active bots** toggle. In the archived view it keeps the existing polling and columns, renders the bot name as plain text, uses an archived-specific empty state, and replaces the `Create bot` / `Kill switch` header actions with a back-to-active link. A restore button per row uses `confirm()` and `router.post`.

`Bots/Show.vue` shows an **Archive** button in the Controls card only when `canArchive` is true. It uses a confirmation prompt naming the bot and warning that residual inventory is not sold, then `router.delete`. The existing `action` error slot reports a rejected archive.

`Dashboard.vue` and `Alerts/Index.vue` render archived bot names as plain text when the payload marks the bot archived, so history does not link to a 404.

### 8. Docs and test map

`docs/dashboard.md` is a test fixture. Add an archive/restore section, document `bots.destroy` and `bots.restore`, and add `BotArchiveTest` to the test map. `DashboardDocsTest` must add both route names to its documented-route list and `BotArchiveTest` to its feature-test list.

Update `docs/analytics.md` for archived equity inclusion, `docs/alerts.md` and `docs/backtesting.md` for archive-versus-permanent-deletion behavior, `docs/security.md` for the restore ability, and `docs/risk.md` for archived bots being outside per-bot risk evaluation while staying in account daily-loss totals.

## Risks / Trade-offs

- **Archived bots can still hold inventory** -> Archiving does not sell it, and per-bot protective exits stop running. The confirmation warns about this, account daily-loss evaluation still includes the balance, and the operator must zero inventory before archiving if they want it fully retired.
- **Engine account-loss queries still include archived bots** -> This is deliberate: account exposure does not vanish at archive. Archived bots are excluded from per-bot tick, reconciliation, and control paths.
- **`withTrashed()` widens historical relations** -> Only four `belongsTo` relations get it; active bot queries still use the global scope.
- **Credential linkage** -> `ExchangeCredential::bots()` uses the soft-deleting scope, so archived bots no longer appear under the credential even though the nullable FK remains. No credential becomes undeletable because of archiving.
- **Archived view polling** -> The archived view polls the same `bots` prop, and `router.reload` keeps the `?archived=1` query string, so archived rows refresh without extra client state.
- **Existing hard-delete tests** -> `AlertRelationshipsTest`, `BacktestDashboardTest`, and `BacktestSweepModelTest` call `$bot->delete()` and expect null associations. They must switch to `forceDelete()` for permanent-deletion coverage and add archive-retention assertions separately.
- **Reverting the migration** -> Dropping `deleted_at` un-archives every archived bot at once. That is the expected rollback and does not delete any history.
- **Live exchange drift** -> A local no-open-orders check cannot prove the exchange has no untracked orders. The fresh-heartbeat precondition and the engine's stop-time cancellation reduce the risk; this limitation is documented.

## Migration Plan

1. Ship the `deleted_at` migration and model change. Existing rows are active (`deleted_at IS NULL`).
2. Deploy the Laravel control safeguards, engine row filters, routes/controller/policy, and UI together. The archive action stays unavailable for bots that fail the stopped, order-free, or live-heartbeat checks.
3. Update docs and tests in the same change so `DashboardDocsTest`, the feature suite, and Go runtime tests stay green.
4. Rollback: revert the application and engine code first; dropping the column is only needed if the schema must revert, and it restores all archived bots to the active list.
