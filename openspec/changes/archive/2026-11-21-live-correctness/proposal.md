## Why

The MVP could place live orders but accounted for them unsafely: live fills recorded zero fees, strategies placed sells the bot could not cover, exposure ignored open buy orders, per-bot risk-limit rows permanently shadowed account limits, and wallet balance sync overwrote every bot on a shared exchange account with the whole balance. These gaps overstated live P&L and let normal operation trip safety controls or corrupt inventory.

## What Changes

- Live fills record the fee reported by the exchange, prorating the cumulative fee across partial fills, and fall back to the bot's configured fee rate. Base-asset and third-asset fees are converted into inventory and quote terms correctly.
- Sells are limited to base inventory not already reserved by open sell orders. Deferring an uncovered sell is expected behavior and is not recorded as a risk event.
- Position exposure includes the remaining notional of open and pending buy orders.
- Account-scope risk limits apply to bots that have no explicit override; legacy bot-scope shadow rows are removed. Users can view and update limits from the dashboard.
- Balance synchronization and reconciliation skip the whole-wallet comparison when more than one live bot shares an exchange account, recording why the sync was skipped.

## Impact

- Affected specs: `risk-controls`, `bot-runtime`.
- Affected code: `RiskGuard`, `InventoryLedger`, `FeeResolver`, `SyncOrders`, `BotReconciler`, `RiskLimitService`, `DailyLossMonitor`, `BalanceSynchronizer`, `CreateBot`, `CcxtExchangeClient`, risk-limit routes/UI, and a risk-limit cleanup migration.
