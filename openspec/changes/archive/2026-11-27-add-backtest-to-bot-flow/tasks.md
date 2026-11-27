# Tasks

## 1. Source link and schema

- [x] 1.1 Add a forward migration for `bots.source_backtest_run_id` (nullable, indexed, foreign key to `backtest_runs` with `nullOnDelete`) and verify migrate/rollback succeeds
- [x] 1.2 Add the `Bot::sourceBacktest()` and `BacktestRun::derivedBots()` relations, model fillable/casts, and source ID exposure in the bot detail resource; verify relation, source-deletion, and user-deletion lifecycle tests pass
- [x] 1.3 Add factory states/helpers for a completed source run with metrics and exits, a source-linked bot, and a deleted-source bot; verify the new factory coverage tests pass

## 2. Source prefill and safe creation

- [x] 2.1 Add a server-side `BacktestBotPrefill` mapper with `supports()` and full per-strategy mapping: common fields, `level_count ?? levels_per_side`, trailing distance, martingale fields, dynamic fields, dynamic zero-spacing handling, protective exits, and name truncation; verify `BacktestBotPrefillTest` covers every built-in grid strategy, sweep overrides, missing configuration, exits, and the 120-character name limit
- [x] 2.2 Update `StoreBotRequest` and `PreviewBotRequest` so `prepareForValidation` resolves the source run through an owner-scoped `findOrFail`, requires a completed usable snapshot, merges derived values, forces paper, and ignores `preset_key` before rules and engine evaluation; verify `BacktestToBotTest` covers tampered fields, another user's run (404), source run deleted while the form is open, live rejection, and preview/store parity
- [x] 2.3 Update `CreateBot` and `BotController::store` to re-derive source settings server-side, persist the source link, protective exits, strategy configuration, and `bot.created` context atomically, use current user risk limits, and publish no start command; verify creation, rollback, and activity tests pass
- [x] 2.4 Add `can_create_bot` to the backtest run and sweep run resources using completion, ownership, strategy availability, `BacktestBotPrefill::supports()`, exchange allowlist, and symbol validation; verify resource tests cover each true/false case
- [x] 2.5 Verify preview/store parity, engine-unavailable fail-closed behavior, duplicate creation from one run, and source-bot deletion in `BacktestToBotTest`
- [x] 2.6 Verify that deleting a source run clears the link and removes the comparison while preserving the bot; cover the migration and model lifecycle

## 3. Entry points and source-mode UI

- [x] 3.1 Add the create-paper-bot action to the completed backtest run detail page; verify `BacktestDashboardTest` asserts the link is present for completed/usable runs and absent for pending, failed, unsupported, and another user's runs
- [x] 3.2 Add the create-paper-bot action to completed sweep run rows; verify `BacktestSweepDashboardTest` asserts the link is present only for completed/usable rows and points at the correct run
- [x] 3.3 Add source mode to `Bots/Create.vue`: minimal source summary, advanced mode, paper-only badge, source banner with idle-creation note, read-only source fields with accessible semantics, editable name, hidden source ID, no current-price action, watcher gating, and preview with the source ID; verify the frontend build and `BacktestToBotUiTest`
- [x] 3.4 Update `docs/backtesting.md` and `docs/dashboard.md` for the run/sweep entry points and source-mode creation form; verify `DashboardDocsTest` and any backtesting docs assertions pass

## 4. Backtest comparison

- [x] 4.1 Add a `BacktestComparison` value object that computes source metrics, paper metrics, source/current capital, percentage returns (`net / capital * 100`), percentage-point deltas, zero-capital handling, null/empty metrics, modified-since-creation indicator, and the caveat; verify unit tests include 5%-vs-5% zero delta and modified settings
- [x] 4.2 Update `BotController::show` to eager-load the source run, pass the comparison prop, and include it in the five-second partial reload when present; verify `BotDetailTest` covers a linked bot, an unlinked bot, a deleted source, and another user's bot
- [x] 4.3 Add the comparison card to `Bots/Show.vue` with source link, source/current capital, modified indicator, caveat, percent formatting, and no card when the source is absent; verify the frontend build and a bot-detail feature test pass
- [x] 4.4 Update the bot-detail and test-map sections of `docs/dashboard.md`; verify `DashboardDocsTest` passes

## 5. Integration verification

- [x] 5.1 Run the full PHP suite with the documented Docker environment overrides; verify all tests pass except the opt-in `CcxtTestnetTest` skip
- [x] 5.2 Run PHPStan, Pint, the frontend build, and `npm run test:js`; verify no errors, formatting changes, or test failures
- [x] 5.3 Run `openspec validate add-backtest-to-bot-flow --strict`; verify the change is valid
- [x] 5.4 Verify the live safety boundary: source-backed creation is paper-only, no `bots.start` command is published, no live confirmation is rendered, and `LiveModeGuard` is not bypassed
- [x] 5.5 Verify docs fixtures and route/test-name references remain in sync

## Workflow follow-up

- Request a code review after implementation.
- Sync the `backtest-to-bot`, `bot-provisioning`, `trading-dashboard`, and `backtesting` specs into the main specs.
- Archive the change after review and deployment verification.
- Open a pull request and link it to issue #17.
