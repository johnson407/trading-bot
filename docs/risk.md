# Risk Controls

Risk checks are enforced by the Go trading engine, not just the dashboard. Strategies return order intents; only the engine may turn an intent into an order, and every intent passes through its risk guard.

## Limits

Limits are resolved in this order:

1. A bot-scope `risk_limits` row for the bot.
2. A user-scope `risk_limits` row for the bot owner.
3. Application defaults from `config/risk.php`.

| Setting | Config key | Default |
| --- | --- | --- |
| Maximum position notional | `risk.defaults.max_position_notional` | 1000 |
| Maximum open orders | `risk.defaults.max_open_orders` | 20 |
| Daily loss limit | `risk.defaults.daily_loss_limit` | 100 |
| Loss window | UTC calendar day | midnight UTC |

Position notional is projected as `abs(base_inventory) * price` plus the remaining notional of every pending, open, or partially-filled buy, then plus the new buy notional or minus the new sell notional, floored at zero. An order is blocked when the projection exceeds the notional limit, or when open orders plus the new order exceed the open-order limit.

A sell is blocked with `risk.insufficient_inventory` when its quantity exceeds the base inventory that is not already reserved by open sell orders. A quote-only bot therefore places only buys until a buy fills and funds the paired sell; paper mode never reaches the negative-inventory pause by design. Insufficient inventory is an expected deferral, not an incident, so the check records no risk event; it is re-evaluated on the next tick. The risk decision, existing-order lookup, and order creation run inside a transaction that holds the bot row lock, so two concurrent ticks cannot both reserve the same base inventory.

New bots do not receive a bot-scope row, so the account-wide limits apply immediately. **Settings > Risk limits** (`risk-limits.edit`) edits the user-scope row; a bot-scope row, when one exists, still overrides it.

## Daily loss

`DailyLossMonitor` runs inside the Go engine for each running bot and the
account as a whole (see [runtime.md](runtime.md)). A loss is the positive
difference between the start-of-day realized P&L baseline and the current
realized P&L, plus optional unrealized P&L at the current mark.

- The baseline resets at the first evaluation after UTC midnight: `day_start` becomes the new day and `day_start_realized_pnl` captures the bot's realized P&L at that moment.
- A bot without a bot-scope override stores its own baseline in its settings instead of mutating the shared user row.
- A per-bot breach pauses that bot and cancels its pending, open, and partially-filled orders.
- A global breach pauses all of the user's active bots and cancels their open orders.
- Archived bots are excluded from per-bot evaluation and order placement. Their
  realized P&L and residual balances still count in the account-wide daily-loss
  and equity aggregates, because archiving does not close the position.
- A paused bot is never resumed by the day's loss monitor. The startup-only
  stale-tick recovery does not apply to a daily-loss, risk, protective-exit, or
  kill-switch pause.
- Baseline reads fail closed. If the account realized P&L, an existing
  account-scope rollover baseline, or a per-bot baseline cannot be read or
  persisted, the tick records an error and places no orders instead of treating
  the loss as zero. A failed rollover keeps the previous baseline and retries
  on the next tick; it never stores zero. A user with no account-scope row uses
  the default account limit and the current realized total as an in-memory
  baseline for that tick, with no persisted account baseline.

## Protective exits

Each bot can carry optional stop-loss, take-profit, and range-exit rules. They are configured per bot on its detail page and are evaluated by the engine on every running tick after the latest mark price is stored and before the daily-loss check and strategy logic. Null or blank fields disable a rule.

| Rule | Trigger |
| --- | --- |
| Stop-loss | Mark price at or below `average_entry_price * (1 - stop_loss_percent / 100)` |
| Take-profit | Mark price at or above `average_entry_price * (1 + take_profit_percent / 100)` |
| Range exit | Mark price at or below `range_low`, or at or above `range_high` |

Stop-loss and take-profit require positive base inventory and a positive average entry price. A range exit works without inventory and may use only one bound. When several rules match on the same tick, precedence is stop-loss, then take-profit, then range exit, and only one trigger is recorded.

A trigger pauses the bot, cancels its pending, open, and partially-filled orders, records a risk event, and writes a `bot.exit_guard_triggered` activity row. The bot's base and quote inventory are never changed: there is no market order, liquidation, or automatic resume. Exit is manual, and a resumed bot that is still beyond its threshold pauses again on its next tick.

A cancellation failure during the exit is recorded as `risk.cancel_failed` and does not prevent the pause. Trigger events are `risk.stop_loss_triggered` (critical), `risk.take_profit_triggered` (warning), and `risk.range_exit_triggered` (warning); the alert catalog mirrors those severities, so stop-loss reaches email while take-profit and range exits stay in the in-app inbox.

## Manual liquidation on safety pauses

Every automatic safety pause is cancel-only. The covered causes are daily loss, protective exit, exchange failure threshold, repeated tick failure, stale tick, a closed live-trading gate, reconciliation conflict, strategy stop, unsupported exchange, and the kill switch. For every cause the engine:

- cancels pending, open, and partially filled orders;
- submits no market, liquidation, or compensating order;
- leaves base and quote inventory unchanged.

A partial fill that races the cancel keeps its filled quantity in inventory; the unfilled remainder is canceled and retained. Inventory changes only when an exchange fill is applied, never because a bot paused.

The engine has no automatic liquidation path. The operator flattens any remaining inventory manually by following [the manual flatten procedure](live-trading.md#manual-flatten): verify the exchange free balance, place limit orders only, confirm final balances and zero open orders, and record the evidence.

## Kill-switch

Laravel writes `desired_status=paused` for every non-stopped active bot owned by
the user and publishes one `kill_switch` command. Archived bots are excluded. The
engine pauses each bot and cancels its orders. If the heartbeat is missing/expired
or publishing fails, Laravel immediately cancels open orders on the user's live
bots through `CancelBotOrders`. A failure on one order is recorded as a
`risk.kill_switch_cancel_failed` risk event and reported in the result; other
orders and bots continue. This is the emergency control for live trading and it
does not wait for a tick.

## Live-mode prerequisites

Live trading requires all of the following:

- `TRADING_LIVE_ENABLED=true` in the environment (`config('trading.live_enabled')`). The default is `false`.
- The bot mode is `live` and the user has an exchange credential for the bot's exchange.
- The start request confirms `<bot name> on <exchange>` exactly.
- The credential's free quote-asset balance covers the bot's `capital_allocation` at start time.
- The Go engine rechecks the gate, kill switch, status, open-order cap, exposure,
  available inventory, and exchange precision immediately before every live
  place. A blocked place records a risk event; an expected sell deferral with no
  base inventory is not an error.

The first live rollout target is Binance testnet. Testnet uses
`https://testnet.binance.vision` and refuses a custom relay that could rewrite
the request to production. MAX is adapter-tested with fakes only in this change.
Production real-money live trading requires Johnson's explicit yes for that
exact action.

Paper mode is the default and never sends private exchange orders. There is no silent fallback from live to paper; a live failure pauses the bot and records the error.

## Risk events

Risk events are queryable per bot and per user, newest first. Important types include `risk.position_limit`, `risk.invalid_order`, `risk.open_order_limit`, `risk.daily_loss_limit`, `risk.kill_switch_cancel_failed`, `exchange.failure_threshold`, `risk.stop_loss_triggered`, `risk.take_profit_triggered`, `risk.range_exit_triggered`, `risk.cancel_failed`, `risk.live_cancel_failed`, `risk.stop_cancel_failed`, `risk.live_trading_disabled`, `risk.live_start_refused`, and `risk.live_order_rejected`. Each event stores severity, message, and JSON context.

Invalid orders are aggregated per placement attempt: one `risk.invalid_order` event records `orders_skipped` and a `reasons` breakdown instead of one event per skipped order. Repeats for the same bot and event type are suppressed for five minutes, like position-limit blocks.

## Fees

Every fill records a fee. For live fills, `FeeResolver` uses the cumulative fee reported by the exchange and subtracts fees already recorded on the order, so partial syncs never double-charge. When the exchange reports no fee, the bot's configured `fee_rate` is applied to the filled notional. An explicitly reported zero stays zero, and a negative fee is credited as a rebate.

Fees may be charged in the quote asset, the base asset, or a third asset (for example BNB). `InventoryLedger` handles each: a base-asset fee reduces the received base quantity (buys) or increases the base disposed of (sells); a third-asset fee is valued at the fill price for P&L and `fees_paid`. Every fee reduces realized P&L and increments `fees_paid` in quote terms, so live results are not overstated.

## Balance synchronization

Migration `2026_11_21_000100_remove_bot_scope_risk_limit_shadows` removes legacy auto-created bot-scope risk rows so the user-level limits apply again. Explicit per-bot overrides are still available through `RiskLimitService::updateForBot`.

Live inventory is updated from exchange order snapshots as fills and partial
fills reconcile. The engine does not yet refresh a whole-account balance into a
live bot on every tick: when shared-account balance sync lands it must refresh
only when a single live bot uses that credential, and record
`bot.balance_sync_skipped` with `reason: shared_account` instead of guessing
per-bot attribution.
