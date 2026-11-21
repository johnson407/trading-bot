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

Position notional is projected as `abs(base_inventory) * price`, plus the new buy notional or minus the new sell notional, floored at zero. An order is blocked when the projection exceeds the notional limit, or when open orders plus the new order exceed the open-order limit.

## Daily loss

`DailyLossMonitor` evaluates each running bot and the account as a whole. A loss is the positive difference between the start-of-day realized P&L baseline and the current realized P&L, plus optional unrealized P&L at the current mark.

- The baseline resets at the first evaluation after UTC midnight: `day_start` becomes the new day and `day_start_realized_pnl` captures the bot's realized P&L at that moment.
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
