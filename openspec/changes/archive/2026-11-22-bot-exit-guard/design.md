# Design

## Context

See `proposal.md` for motivation. The current risk path has three relevant properties:

- `RiskGuard` only checks order exposure and open-order count. It has no notion of an open position crossing a price level.
- `DailyLossMonitor` establishes the pause-and-cancel pattern: transition the bot row to `Paused` under a row lock, cancel pending/open/partially-filled orders through `CancelBotOrders`, then record cancellation failures and a `risk.daily_loss_limit` event. It does not liquidate inventory.
- `BotTickPipeline` stores the latest mark in `bots.settings.mark_price`, then runs the daily-loss check before building the strategy snapshot. A protective exit must run between those two points so a triggered bot never places another strategy order.

`CancelBotOrders` currently hardcodes the kill-switch cancellation-failure event. `ExchangeClient` has no market-order method, so liquidation would require a new execution path across paper, live, and backtest. Backtests snapshot risk limits through `BacktestRiskLimits` and currently end with `range_end`, `risk_pause`, or `strategy_stop`. Alerts are created by recording a catalogued risk event; the catalog lives in `config/alerts.php`.

## Goals / Non-Goals

**Goals:**

- One pure rule evaluation usable by both live ticks and candle replay.
- Optional per-bot stop-loss, take-profit, and range rules, editable without stopping a running bot.
- Deterministic precedence and one recorded trigger per evaluation.
- Pause and cancel without changing inventory.
- Backtest parity for rule evaluation, with distinct terminal outcomes.
- Alerts and activity entries for every trigger and cancellation failure.

**Non-Goals:**

- Market orders, exchange-native stop orders, or automatic liquidation.
- Automatic resume, trailing stops, or per-grid-level exits.
- Account-wide exit rules or changes to the existing exposure and daily-loss limits.
- Changing how fills, fees, or inventory are calculated.

## Decisions

### Store rules as nullable columns on `bots`

Add `stop_loss_percent`, `take_profit_percent`, `range_low`, and `range_high`, all nullable. Null means disabled. A bot has exactly one rule set, so a separate `bot_exit_rules` table adds a join and lifecycle without adding capability. Exchange-native stop orders were rejected because they cannot be represented consistently in paper mode or the close-only backtest model and would fragment the source of truth.

Percentages are stored as `decimal(8,4)` and prices as `decimal(16,8)`. Casts expose them as decimal strings, matching the rest of the bot model. The migration is additive; existing bots have all rules disabled.

### Percentages are relative to average entry

Stop-loss triggers when the bot holds base inventory and the mark is at or below `average_entry_price * (1 - stop_loss_percent / 100)`. Take-profit triggers at or above `average_entry_price * (1 + take_profit_percent / 100)`. This follows the changing cost basis of a grid: absolute stop prices would need manual adjustment after every fill. Range bounds remain absolute prices because they describe market structure rather than position cost.

Percentage rules are skipped when base inventory is zero or average entry price is zero or missing. The range rule still applies because it does not depend on inventory. A range may be configured with only one bound.

### Evaluate after the mark update, before daily loss and strategy

`BotTickPipeline` calls the exit monitor immediately after persisting `settings.mark_price`, before `DailyLossMonitor`. A protective exit is per-bot and more specific than an account-wide daily-loss pause, so it should win when both are true. Precedence within the guard is stop-loss, take-profit, then range. Only the first match is recorded; a stop-loss and range breach on the same tick produces one stop-loss event.

The rule evaluator is a pure value object, `ExitRules`, returning an `ExitSignal` or null. Live execution and backtesting both call it, so threshold arithmetic cannot drift between the two.

### Pause and cancel through the existing pattern

A new `ExitGuardMonitor` receives the bot and mark. It:

1. Evaluates `ExitRules`.
2. Locks the bot row and returns unless the status is still `Running`. This makes manual pause and exit trigger idempotent.
3. Sets `Paused`, stores a concise `last_error`, and commits the status change.
4. Calls `CancelBotOrders` outside the transaction with an exit-specific failure type.
5. Records `risk.stop_loss_triggered`, `risk.take_profit_triggered`, or `risk.range_exit_triggered` with the rule, observed price, threshold, average entry, and base inventory.
6. Records activity `bot.exit_guard_triggered`.

`CancelBotOrders` gains optional failure type and message parameters defaulting to the current kill-switch values, so existing callers are unchanged. Exit cancellation failures record `risk.exit_cancel_failed`; the remaining orders are still attempted. The guard never writes inventory.

A paused bot is skipped at the start of `BotTickPipeline`, so it does not retrigger. If the user resumes while the price is still beyond a threshold, the next tick pauses it again. That is intentional: resuming is an explicit decision, not a reset of the rule.

### Configure through a dedicated bot endpoint and detail card

Add `PATCH /bots/{bot}/exit-guard` and `BotExitGuardController@update`. The endpoint authorizes ownership, accepts blank values as null, validates percentages and range ordering, locks the bot row, updates only the fields present in the request, and records `bot.exit_guard_updated` with the before/after values. Omitted fields keep their stored rules; the range-order check uses the merged stored and submitted bounds. It does not reuse `UpdateBotRequest` or the `bots.update` route, because those are intentionally limited to idle or stopped bots and must not become a path for changing strategy structure while running.

`Bots/Show.vue` gets an exit-guard card populated from the bot resource. The card is available in every status, shows the current rules, and posts to the new route. `BotSummaryResource` exposes the four values so the detail page and backtest creation can use them.

### Snapshot the rules into each backtest

`BacktestConfiguration` gains an `ExitRules` value object. `BacktestSnapshotBuilder` reads the bot's columns when creating the snapshot. `BacktestEngine` evaluates the snapshot at each candle close before the existing daily-loss check, matching live order. On a trigger it cancels simulated orders, records the closing equity point, and ends with `StopLoss`, `TakeProfit`, or `RangeExit`. `BacktestEndReason` gains those three cases, and the run detail view displays the stored reason.

The close-only model means backtest exits trigger on candle close, while live exits can trigger on an intra-tick mark. `docs/backtesting.md` will state that difference.

### Alert severity

Add catalog entries: stop-loss critical, take-profit warning, range exit warning, and exit-cancellation failure critical. A stop-loss is an adverse event that warrants email; a take-profit or range exit remains in-app. The existing 15-minute dedupe window prevents a resume-and-retrigger loop from flooding the inbox.

## Risks / Trade-offs

- **A pause is not a liquidation.** Inventory remains exposed after the trigger. This is the chosen and documented behavior; the alert and bot `last_error` make the state visible, and the user can exit manually.
- **A stale or illiquid mark can trigger a rule.** Evaluation is tick-gated and uses the same mark already used for P&L. Users can widen rules or use range bounds; the guard performs no market action beyond canceling orders.
- **Rule changes while running apply on the next tick.** The update is row-locked and logged. A tightening can trigger on the next mark, which is the expected safety behavior.
- **Live and backtest timing differ.** Live can trigger on any tick mark; backtests trigger at candle close. The docs explicitly flag this, and the evaluator itself is shared.
- **Backtest outcomes multiply.** Adding three end reasons affects result labels and any tests that assert the allowed set. The engine change is limited to one check and the enum/UI presentation.

## Migration Plan

1. Deploy the additive migration with all columns nullable; no backfill is needed and existing bots keep every rule disabled.
2. Deploy code; old rows evaluate to no rules.
3. Roll back by dropping the columns and reverting the engine/UI changes. No historical data migration is required.

## Open Questions

None that change the specs, approach, or task breakdown.
