# Proposal

## Why

Bots can be created but never removed. A stopped or unused bot stays in the bot list, dashboard counts, backtest selectors, and alert filters forever, so the UI has no way to retire old configurations. Deleting the rows is unsafe because orders, fills, backtests, alerts, and activity reference the bot.

Archiving must also be safe against in-flight control requests and must not hide money. A stale `bot.start` must not resurrect an archived bot, and archiving must not make the account's realized or unrealized totals drop while the bot still holds inventory.

## What Changes

- Add an **archive** action that soft-deletes a bot, hiding it from active bot listings, selectors, operational counts, and the kill switch while keeping every related record and foreign key intact.
- Require the bot to be fully stopped before archiving: both `status` and `desired_status` are `stopped` and it has no pending, open, or partially filled orders. A live bot additionally requires a fresh engine heartbeat. Otherwise the request is rejected with a clear error.
- Make archive final against stale controls: Laravel start/pause/stop writes only to non-archived rows, and the Go engine excludes archived rows from command application, tick selection, stale checks, reconciliation, and live-order sync.
- Keep account money visible: archived bots' realized P&L, unrealized P&L, and inventory remain in dashboard aggregates and equity snapshots, while operational bot counts include active bots only. Account-level daily-loss evaluation continues to include archived balances.
- Add an **archived view** on the bot list and a **restore** action that moves an archived bot back to the normal list in its stopped state.
- Keep historical references usable: activity rows, alerts, backtest runs, and sweeps that point at an archived bot continue to show the bot's name. Dashboard and alert references render an archived bot's name as plain text instead of linking to a detail page that no longer resolves.
- Add an **Archive** control on the bot detail page with explicit confirmation, visible only when the bot meets the archive preconditions and refreshed with the page's polling.
- Update documentation and tests, including the existing permanent-deletion tests that must use `forceDelete()` so they keep testing hard deletion rather than archive.

## Capabilities

### New Capabilities

- `bot-lifecycle`: archiving and restoring a bot, eligibility rules, history retention, and what archived bots may no longer do.

### Modified Capabilities

- `trading-dashboard`: the bot list gains an archived view and restore action, the detail page gains an archive action, and dashboard counts and historical references distinguish active from archived bots.
- `alerts`: an alert keeps its bot association and name when the bot is archived, while permanent deletion still clears the association.
- `analytics`: account equity snapshots include archived bots' balances, realized P&L, and unrealized P&L.
- `bot-runtime`: the engine excludes archived bots from control and tick evaluation while keeping their balances in account-level daily-loss evaluation.

## Impact

- Laravel: `bots` table soft-delete column, `Bot` model, start/pause/stop actions, kill switch, `BotController` index/show/destroy/restore, `BotPolicy`, routes, `DashboardController`, `EquitySnapshotter`, and the bot relations on `ActivityLog`, `Alert`, `BacktestRun`, and `BacktestSweep`.
- Go engine: `deleted_at IS NULL` guards in bot loading, status application, reconciliation, stale checks, and live-order sync; account-wide daily-loss queries intentionally keep all bots; runtime tests.
- Vue: `resources/js/Pages/Bots/Index.vue`, `Show.vue`, `Dashboard.vue`, and `Alerts/Index.vue`.
- Documentation: `docs/dashboard.md` (asserted by `DashboardDocsTest`), `docs/analytics.md`, `docs/alerts.md`, `docs/backtesting.md`, `docs/security.md`, and `docs/risk.md`.
- Tests: new archive/restore, runtime-race, financial-aggregate, link-rendering, and engine coverage; updates to list, detail, authorization, alert, backtest, sweep, analytics, and docs tests.
- No exchange credential, order-placement, live-gate, or risk-limit rule changes. The only live-specific addition is the archive heartbeat precondition.
