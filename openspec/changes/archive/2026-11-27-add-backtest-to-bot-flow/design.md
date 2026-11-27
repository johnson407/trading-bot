# Design

## Context

See `proposal.md` — Why. The existing pieces already carry most of what this workflow needs:

- `BacktestRun` stores an immutable `config_snapshot` containing the source bot, strategy configuration, risk limits, protective exits, and period. `BacktestSweep` runs are ordinary `BacktestRun` rows with `sweep_id` set and the swept parameters applied to the snapshot.
- `CreateBot` already creates the bot, inventory, strategy configuration, and `bot.created` activity in one transaction.
- `Bots/Create.vue` already contains the full creation form, quick/advanced modes, engine preview, and paper/live handling.
- `BacktestRunResource` exposes the run status, metrics, and snapshot; `BacktestSweepRunResource` exposes the swept values and metrics but not the snapshot.
- `backtest_runs.bot_id` records the source bot used to create a backtest; it is not a link from a bot back to a backtest.

There is no bot-to-backtest link today, no create-bot action on run or sweep pages, and no paper-versus-backtest comparison on the bot detail page.

## Goals / Non-Goals

**Goals:**

- Make a completed run or sweep row an entry point into the existing creation form.
- Create a paper bot that faithfully reproduces the snapshot's strategy settings and protective exits.
- Derive source settings before validation and engine evaluation so preview and store agree.
- Persist an optional source link that clears when the source run is deleted.
- Show an honest comparison with consistent percentage units and a modified-since-creation indicator.

**Non-Goals:**

- Creating live bots from a backtest, auto-starting a bot, or changing the live gate.
- Copying historical risk limits; current user risk limits apply.
- Overlaying paper and backtest equity curves, or building a new analytics store for paper drawdown.
- Creating a bot from a sweep aggregate or from a pending/running/failed run.
- Editing the source run from the bot or changing the link after creation.
- A durable "source deleted" state; after deletion the comparison simply disappears.
- A Go engine change, a new creation route, or a data backfill.

## Decisions

### 1. Link with a nullable foreign key on `bots`

Add `bots.source_backtest_run_id`, nullable, indexed, foreign key to `backtest_runs.id` with `nullOnDelete`.

- `Bot::sourceBacktest()` is a `belongsTo` relation; `BacktestRun::derivedBots()` is the inverse `hasMany`.
- The column is set only by the source-backed creation path and is not part of bot editing.
- `nullOnDelete` preserves the bot and its history when a run is deleted. The link becomes null, so the comparison disappears and the bot looks like any other bot created without a source. There is no durable deleted-source descriptor.
- Add a test that deletes a source run and asserts the bot survives, the link is null, and no comparison is rendered.
- Add a user-deletion lifecycle test: deleting a user must not fail on the circular bot/run foreign-key relationship.

Alternative considered: a `bot_backtest_links` table. It is unnecessary because a bot has at most one source run and the link is immutable. Alternative considered: reuse `backtest_runs.bot_id`. That column already means "the bot this run was created from"; overloading it would corrupt backtest provenance.

### 2. Entry points and capability split

Completed run detail pages and completed sweep rows link to:

`route('bots.create', ['source_backtest_run_id' => $run->id])`

No new form route is needed. `bots.store` and `bots.preview` receive the optional `source_backtest_run_id`.

The requirements are split across capabilities:

- `backtest-to-bot` owns the source link, the end-to-end run/sweep workflow, and ownership.
- `backtesting` owns the run/sweep page actions and the `can_create_bot` resource flag.
- `bot-provisioning` owns source-backed server creation, prefill mapping, protective exits, and engine evaluation.
- `trading-dashboard` owns the source-mode creation UI and the comparison card.

`BacktestRunResource` and `BacktestSweepRunResource` gain a `can_create_bot` boolean. It is true only when the run is completed, belongs to the viewer, its strategy is available, `BacktestBotPrefill::supports()` accepts the snapshot, the exchange is still in `config('trading.exchanges')`, and the symbol matches the creation rule. The server computes the flag so the Vue pages do not duplicate registry or allowlist knowledge. The sweep run resource does not expose `config_snapshot`; the create page loads the run by ID server-side.

### 3. Source prefill is a server-owned mapping

Add `App\Domain\Backtest\BacktestBotPrefill` (name illustrative; implementation may live beside the existing backtest domain services). It exposes `supports(array $snapshot): bool` and maps a completed run's snapshot to creation-form values:

- `name` default: `<run name> paper`, truncated to the 120-character creation limit (editable)
- `exchange`, `symbol`, `strategy_key`
- `capital_allocation`, `fee_rate`
- `center_price`, `level_count`, `spacing_type`, `spacing_value`, `order_quantity`
- `level_count` uses `level_count ?? levels_per_side`; sweep runs already carry both where needed
- simple grid: the common fields only
- trailing grid: `trailing_distance`
- martingale grid: `base_order_quantity`, `size_multiplier`, `max_order_quantity`, `max_increases`
- dynamic grid: `min_spacing`, `max_spacing`, `volatility_multiplier`, `recalculation_interval_seconds`; a zero or absent `spacing_value` is omitted or replaced with a valid positive value rather than submitted as zero
- protective exits: `stop_loss_percent`, `take_profit_percent`, `range_low`, `range_high` when present; absent exits use the normal creation defaults
- `mode`: `paper`
- `source_backtest_run_id`

If the snapshot lacks a usable strategy configuration, or the strategy is unavailable, the mapping fails and no form is rendered. The mapper is covered for every built-in grid strategy and for sweep overrides, including the `levels_per_side` fallback and dynamic zero spacing.

`BotController::create` loads the source run by ID through an owner-scoped query, checks completion and `supports()`, and passes a minimal `sourceRun` summary plus `initial` values to `Bots/Create.vue`. Invalid or deleted source IDs redirect back with an error rather than rendering a partially prefilled form.

### 4. Derivation happens before validation and engine evaluation

`StoreBotRequest` and `PreviewBotRequest` accept `source_backtest_run_id` as a nullable existing run ID. In `prepareForValidation`, when the ID is present:

- Resolve the run with `$request->user()->backtestRuns()->findOrFail($id)`, so another user's run is a 404 rather than a 403 that reveals existence.
- Require a completed run and a snapshot accepted by `BacktestBotPrefill::supports()`.
- Merge the snapshot-derived values into the request, force `mode = paper`, and ignore any submitted `preset_key`.
- Let the normal rules and `withValidator` run against the derived values, including the engine plan evaluation.

`BotController::store` re-derives from the snapshot as defense-in-depth before calling `CreateBot`. This prevents tampered fields from passing validation for a plan that is then discarded, and keeps preview and store aligned. A source run deleted while the form is open resolves as not found and creates no bot.

### 5. Source mode in the creation form

`Bots/Create.vue` gains `sourceRun` and `initial` props. When `sourceRun` is present:

- The form starts in advanced mode; the quick/advanced toggle is hidden.
- The mode selector and live-confirmation UI are hidden; a **Paper** badge states that source-backed bots are paper-only.
- A source banner shows the run name, period, strategy, and symbol, with a note that settings come from the backtest, risk limits use the user's current limits, and the bot will be created idle and must be started manually.
- The name remains editable. Source-derived fields are rendered read-only through a definition list or `aria-readonly` semantics, not merely disabled inputs.
- The "use current price" action and market notice are hidden.
- Initial source values are applied before default-field watchers can overwrite them.
- Submit posts the hidden `source_backtest_run_id`.

The existing engine preview still runs for the prefilled plan. The preview call includes the source ID so the server derives the same settings; a client cannot make preview and save disagree by editing a read-only field. The page receives a minimal source summary, not the full snapshot or equity curve.

### 6. Creation remains paper and idle

Source-backed creation forces `mode = paper`. It does not call `LiveModeGuard`, does not show the live confirmation, and does not publish `bot.start`. The created bot is idle and the user starts it explicitly from the normal bot controls. Risk limits are resolved by the existing `RiskLimitService` for the current user, not copied from the snapshot.

Protective exits are copied because they are part of the strategy snapshot and affect the plan. `CreateBot` persists the source link, protective exits, strategy configuration, and `bot.created` activity in the same transaction; the activity context records `source_backtest_run_id`. The design does not copy the backtest's historical risk limits.

Multiple bots may be created from the same completed run. Each links independently; no unique constraint is added.

### 7. Comparison is a small read model on bot detail

`BotController::show` eager-loads `sourceBacktest` and passes a `backtestComparison` prop (null when there is no source). A small `BacktestComparison` value object computes:

- **Source:** run name, period, status, net P&L, return percentage, fees, fill count, max drawdown.
- **Paper:** realized + unrealized P&L, return percentage (`net / current capital * 100`), fees paid, fill count.
- **Capital:** source capital and current paper capital, both shown.
- **Modified indicator:** true when capital, fee rate, strategy configuration, or protective exits differ from the source snapshot.
- **Deltas:** paper minus backtest for net P&L, return in percentage points, fees, and fills.
- **Caveat:** the periods differ (backtest is historical; paper runs from creation), backtests use close-only fills and historical candles, paper uses live market data, risk limits are current rather than historical, and the bot may have been edited since creation. The comparison is directional, not a promise of equivalence.

Returns are percentages in both result sets. Paper return is `net / current capital * 100`; the backtest metric is already `return_percent`. Deltas are percentage points. A 5% paper return and a 5% backtest return produce a zero delta. Zero capital yields a null return rather than a division error. Null or empty source metrics render as unavailable rather than fabricated numbers. Paper max drawdown is not shown because the bot does not persist a per-bot drawdown series.

All money uses `App\Domain\Support\Decimal`; the frontend formats money with `formatMoney`/`formatSignedMoney` and returns as percentages, not currency. The card refreshes with the existing 5-second bot poll and is included in the partial reload when it has data.

The card links to `backtests.show` only while the source link exists. If the source run is deleted, the link is null and the card is not shown; the bot remains fully usable.

### 8. Resources and UI surface

- `BacktestRunResource`: add `can_create_bot`; pass a minimal source summary to the create page instead of the heavy snapshot/equity payload.
- `BacktestSweepRunResource`: add `can_create_bot`; keep `config_snapshot` out of the list payload.
- `BotSummaryResource` or the bot detail props: expose `source_backtest_run_id` and the comparison summary; do not expose the full snapshot.
- `Backtests/Show.vue`: add the create-paper-bot action in the header for completed, available runs.
- `Backtests/Sweeps/Show.vue`: add a compact action in the run row (or an action column) for completed, available runs; keep the existing run link.
- `Bots/Create.vue`: source mode as described above.
- `Bots/Show.vue`: comparison card, source link, modified indicator, caveat, and no card when the source is absent.

### 9. Migration and rollout

One forward migration adds the nullable column and foreign key. There is no backfill and no data rewrite. Deploy the migration before the application code; old code ignores the new column. Rollback drops the foreign key and column; bots and runs otherwise remain intact. No Go engine change is required.

## Risks / Trade-offs

- **Comparison can mislead** → Show both periods, label the backtest as historical, state close-only fills, live-market differences, current risk limits, and post-creation edits. Show both capitals and a modified indicator. Do not claim equivalence.
- **Mixed return units** → Both returns are percentages; deltas are percentage points; add a 5%-vs-5% zero-delta test.
- **Client tampering** → Derive source settings in `prepareForValidation` and re-derive in the controller; treat read-only inputs as display only.
- **Snapshot shape drift** → The prefill mapper is strategy-aware and fails closed; tests cover every built-in grid strategy, sweep overrides, and dynamic zero spacing.
- **Source deletion** → `nullOnDelete` clears the link; the comparison disappears and the bot continues. There is no deleted-source state to maintain.
- **N+1 or heavy fills** → Eager-load the source run on bot detail and use a count query for fills; do not load the full fill history for the comparison.
- **Accidental live creation** → Reject live mode for source-backed requests and never render the live confirmation in source mode.
- **Stale or inappropriate risk limits** → Use the user's current risk limits and state that in the card; only the strategy snapshot's protective exits are copied.
- **Sweep list payload growth** → Do not add `config_snapshot` to `BacktestSweepRunResource`; load the selected run server-side on the create page.
- **Engine unavailable** → The existing preview/evaluation path fails closed, so no bot is persisted.
- **Duplicate creation** → Multiple bots may link to one run; each is independent and no unique constraint is added.
- **User deletion with linked rows** → The nullable FK and `nullOnDelete` avoid a circular delete failure; cover it with a lifecycle test.

## Migration Plan

1. Add `bots.source_backtest_run_id` nullable FK with `nullOnDelete` and an index.
2. Deploy the Laravel/model/resource/UI changes.
3. Run the full PHP suite, PHPStan, Pint, frontend build/JS tests, and docs-fixture tests.
4. No Go deployment is required.

Rollback: drop the foreign key and column. The bot rows remain; only the source link and comparison disappear.

## Open Questions

None that change the specs, approach, or task breakdown. The exact visual placement of the sweep-row action can be settled during implementation without changing behavior.
