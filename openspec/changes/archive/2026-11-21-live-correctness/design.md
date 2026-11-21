## Context

The engine already had a single fill path (`FillProcessor` + `InventoryLedger`), a per-order risk check, and scheduled balance sync. The defects were in how those pieces valued fees, counted exposure, resolved limits, and handled shared exchange accounts.

## Decisions

### Fees

`ExchangeOrder` carries `fee` and `feeCurrency` mapped from CCXT `fee`/`fees`. `FeeResolver::forFill()` treats the reported fee as cumulative for the order: it subtracts the fees already recorded on the local order for the same currency and records only the delta, so two partial syncs cannot double-count. When the exchange reports no fee, the bot's `fee_rate` applies to the filled notional.

`InventoryLedger` converts a fee denominated in the base asset into a quantity reduction (buy) or an additional quantity sold (sell). A fee in any third asset is valued at the fill price for quote accounting and `fees_paid`. Realized P&L is reduced by the quote-equivalent fee.

### Funded sells

`RiskGuard` subtracts the remaining quantity of open sell orders from base inventory before allowing another sell. An uncovered sell is deferred with `risk.insufficient_inventory` at Warning severity and no risk event, because grid strategies legitimately retry after the covering buy fills.

### Exposure

Projected position notional = absolute base inventory at the order price + remaining notional of open and pending buys + the candidate order. Sells reduce the projection but never below zero.

### Limits

`CreateBot` only ensures the user-scope row. `RiskLimitService::forBot()` returns an explicit bot override when one exists, otherwise the user row. `updateForBot()` creates an override deliberately. A data migration deletes legacy auto-created bot-scope rows. Bots without an override persist their own daily-loss baseline in `bots.settings['daily_loss_baseline']`, so the shared user baseline is never rewritten by one bot.

### Shared accounts

When more than one live bot shares a credential (or the same user with no credential), `BalanceSynchronizer` skips the wallet sync and records `bot.balance_sync_skipped` with `reason: shared_account`. `BotReconciler` skips the whole-wallet quote comparison on the same condition so a routine restart cannot force a false reconciliation conflict.

## Risks

- Check-and-place for sells is not atomic with respect to concurrent ticks; the reservation is computed per call. A per-bot lock remains future work.
- A reported fee of zero is still indistinguishable from a missing fee, and negative rebates are ignored.
