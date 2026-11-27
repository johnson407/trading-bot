# Proposal

## Why

Backtest and sweep results currently dead-end at the report. To act on a promising result, the user must manually recreate its exchange, symbol, strategy, capital, fees, grid parameters, and protective exits in the bot creation form. That is slow and risks transcription errors. The user also has no built-in way to see whether the paper bot that follows actually tracks the backtest it came from. Issue #17 item 4 asks for a one-step paper-bot creation from a backtest or sweep result, followed by a paper-versus-backtest comparison.

## What Changes

- Add an optional, read-only link from a paper bot to the completed backtest run it was created from. Deleting the source run clears the link; the bot is preserved and the comparison disappears.
- Add a **Create paper bot** action to completed backtest run detail pages and completed rows in sweep detail pages. The action opens the existing bot creation form prefilled from the run's immutable configuration snapshot. "One step" means prefill plus confirmation; it does not bypass the existing preview or validation.
- Source-backed creation forces paper mode, creates the bot idle, and never starts it. It copies the snapshot's exchange, symbol, strategy, capital, fee, grid configuration, and protective exits. Historical risk limits are not copied; the user's current risk limits apply.
- Derive source settings server-side before request validation and engine plan evaluation, so preview and save use the same snapshot values. Browser-submitted source-derived values are not trusted.
- Add a **Backtest comparison** section to the bot detail page showing the source run, source and current capital, paper-versus-backtest net P&L, return percentage, fees, and fill counts, with deltas, a modified-since-creation indicator, and a caveat about periods, execution, risk limits, and edits.
- Allow multiple bots to be created from the same completed run; each links independently.

## Capabilities

### New Capabilities

- `backtest-to-bot`: the end-to-end workflow for opening a completed run or sweep run in the existing creation form, preserving the source link, and enforcing owner-scoped access.

### Modified Capabilities

- `backtesting`: completed run and sweep pages expose a create-paper-bot action and resource flags that reflect whether a run's snapshot is usable.
- `bot-provisioning`: source-backed creation derives snapshot settings, copies protective exits, forces paper/idle creation, and uses the existing engine evaluation path.
- `trading-dashboard`: source mode in the bot creation form and the backtest comparison card on bot detail.

## Impact

- **Data:** one nullable `bots.source_backtest_run_id` foreign key to `backtest_runs` with `nullOnDelete`; no backfill and no durable deleted-source descriptor. Existing backtest runs and bots are otherwise unchanged.
- **Laravel:** migration, `Bot`/`BacktestRun` relations, `BotController` create/store/show, `StoreBotRequest`/`PreviewBotRequest`, a `BacktestBotPrefill` mapper, a `BacktestComparison` read model, bot and backtest resources, and `CreateBot`.
- **Frontend:** `Backtests/Show.vue`, `Backtests/Sweeps/Show.vue`, `Bots/Create.vue`, and `Bots/Show.vue`.
- **Docs and tests:** `docs/backtesting.md`, `docs/dashboard.md`, `BacktestDashboardTest`, `BacktestSweepDashboardTest`, `BotCreationTest`, `BotDetailTest`, `DashboardDocsTest`, plus `BacktestToBotTest`, `BacktestBotPrefillTest`, and `BacktestToBotUiTest`.
- **Safety:** source-backed creation is paper-only and idle; the live gate, exact live confirmation, credential check, and balance check are untouched. No start command is published. Risk limits remain the user's current limits.
- **Out of scope:** live bot creation from a backtest, auto-start, copying historical risk limits, overlaying equity curves, creating from pending/running/failed runs, creating one bot from a sweep aggregate instead of a specific run, paper max drawdown, soft-deleting backtest runs, and a durable "source deleted" state.
