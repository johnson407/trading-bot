# Proposal

## Why

Bots currently have no per-position exit rule. The only automatic stop is the daily-loss limit, which is based on the day's P&L and can be far looser than the user's tolerance for a single position. A grid can keep holding inventory through a large adverse move and keep placing orders into it. The dashboard documents that a stop-loss which pauses and cancels orders is the next operational gap now that alerts exist.

## What Changes

- Add optional per-bot protective exit rules: stop-loss percentage, take-profit percentage, and an absolute price range with lower and upper bounds. A null rule is disabled.
- Evaluate the rules on each running bot tick, after the mark price is stored and before the daily-loss check and strategy tick. Precedence is stop-loss, then take-profit, then range.
- On a trigger, pause the bot, cancel its open and pending orders, record a risk event with the rule, observed price, and configured threshold, emit an activity entry and an alert, and leave the base inventory untouched. The bot does not resume automatically.
- Add a bot-detail form and endpoint for viewing and changing the rules. The form remains editable while the bot is running or paused so risk can be tightened without stopping the bot.
- Snapshot the rules into backtest runs and enforce the same rules at candle close, with new `stop_loss`, `take_profit`, and `range_exit` outcomes.
- Add alert-catalog entries for stop-loss, take-profit, range exit, and exit-cancellation failure.

**Out of scope:** market or exchange-native stop orders, automatic liquidation, automatic resume, account-wide exit rules, trailing stops, and per-grid-level exits.

## Capabilities

### New Capabilities

<!-- None. This change extends existing risk, dashboard, backtest, and alert behavior. -->

### Modified Capabilities

- `risk-controls`: add per-bot protective exit rules, their evaluation order, pause-and-cancel behavior, event recording, and no-automatic-resume semantics.
- `trading-dashboard`: show the configured exit rules on the bot detail page and allow an owner to update them in any bot state with validation and feedback.
- `backtesting`: snapshot exit rules with the run, enforce them during replay, and report the new terminal outcomes.
- `alerts`: include protective-exit triggers and exit-cancellation failures among the incidents that create owner-scoped alerts.

## Impact

- **Data:** four nullable columns on `bots` (`stop_loss_percent`, `take_profit_percent`, `range_low`, `range_high`) and their model casts.
- **Runtime:** new exit-rule evaluator and monitor called from `BotTickPipeline`; `CancelBotOrders` gains a configurable failure event so exit cancellations do not report as kill-switch failures.
- **Dashboard:** new `PATCH /bots/{bot}/exit-guard` route/controller/request, bot detail props and card, activity entry on change.
- **Backtesting:** `BacktestConfiguration`/snapshot builder carry exit rules; `BacktestEngine` evaluates them; `BacktestEndReason` gains three cases and the detail view displays them.
- **Alerts:** four new catalog entries in `config/alerts.php`.
- **Docs and tests:** risk, bot-parameters, dashboard, backtesting, alerts, and next-features docs; unit and feature coverage for evaluation, pause/cancel, configuration, backtest parity, and alerts.
