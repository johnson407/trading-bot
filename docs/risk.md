# Risk Controls

Risk checks are enforced by the trading engine, not just the dashboard. Strategies return order intents; only the pipeline may turn an intent into an order, and every intent passes through `RiskGuard`.

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

New bots do not receive a bot-scope row, so the account-wide limits apply immediately. `Risk limits` in the navigation (`risk-limits.edit`) edits the user-scope row; a bot-scope row, when one exists, still overrides it.

## Daily loss

`DailyLossMonitor` evaluates each running bot and the account as a whole. A loss is the positive difference between the start-of-day realized P&L baseline and the current realized P&L, plus optional unrealized P&L at the current mark.

- The baseline resets at the first evaluation after UTC midnight: `day_start` becomes the new day and `day_start_realized_pnl` captures the bot's realized P&L at that moment.
- A bot without a bot-scope override stores its own baseline in its settings instead of mutating the shared user row.
- A per-bot breach pauses that bot and cancels its pending, open, and partially-filled orders.
- A global breach pauses all of the user's bots and cancels their open orders.
- A paused bot is never resumed by the monitor.

## Kill-switch

`ActivateKillSwitch` pauses every non-stopped bot owned by the user immediately, then cancels each bot's open orders in parallel. A failure on one order is recorded as a `risk.kill_switch_cancel_failed` risk event and reported in the result; other orders and bots continue. This is the emergency control for live trading.

## Live-mode prerequisites

Live trading requires all of the following:

- `TRADING_LIVE_ENABLED=true` in the environment (`config('trading.live_enabled')`). The default is `false`.
- The bot mode is `live` and the user has an exchange credential for the bot's exchange.
- The start request confirms `<bot name> on <exchange>` exactly.
- The credential's free quote-asset balance covers the bot's `capital_allocation` at start time.

Paper mode is the default and never sends private exchange orders. There is no silent fallback from live to paper; a live failure pauses the bot and records the error.

## Risk events

Risk events are queryable per bot and per user, newest first. Important types include `risk.position_limit`, `risk.open_order_limit`, `risk.daily_loss_limit`, `risk.kill_switch_cancel_failed`, and `exchange.failure_threshold`. Each event stores severity, message, and JSON context.

## Fees

Every fill records a fee. For live fills, `FeeResolver` uses the cumulative fee reported by the exchange and subtracts fees already recorded on the order, so partial syncs never double-charge. When the exchange reports no fee, the bot's configured `fee_rate` is applied to the filled notional. An explicitly reported zero stays zero, and a negative fee is credited as a rebate.

Fees may be charged in the quote asset, the base asset, or a third asset (for example BNB). `InventoryLedger` handles each: a base-asset fee reduces the received base quantity (buys) or increases the base disposed of (sells); a third-asset fee is valued at the fill price for P&L and `fees_paid`. Every fee reduces realized P&L and increments `fees_paid` in quote terms, so live results are not overstated.

## Balance synchronization

Migration `2026_11_21_000100_remove_bot_scope_risk_limit_shadows` removes legacy auto-created bot-scope risk rows so the user-level limits apply again. Explicit per-bot overrides are still available through `RiskLimitService::updateForBot`.

`bots:sync-balances` refreshes a live bot's base and quote inventory from the exchange only when a single live bot uses that credential. When several live bots share one exchange account, the sync is skipped, per-bot inventory is left untouched, and `bot.balance_sync_skipped` is recorded with `reason: shared_account`, at most once per bot per day. Wallet totals cannot be attributed to individual bots, so each bot's ledger stays authoritative.
