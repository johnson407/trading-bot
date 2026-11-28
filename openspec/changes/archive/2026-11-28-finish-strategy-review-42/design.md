## Context

Issue #42 is a tracking issue from the strategy review. The remaining items touch the Go strategy/backtest runtime, the PHP paper fill adapter, and the dynamic-grid setup form. The live safety gate and risk checks are unchanged.

Current behaviour:
- `placeOrders` and `placeIntents` iterate strategy intents in ascending price order.
- Paper fills and backtest fills use the limit price even when the market trades through it.
- The plan checks minimum amount/notional but does not model live rounding.
- `initial_spacing` is honoured by Go when present but is not written by the form.
- Trailing/dynamic disarmed maps are keyed by price and are never pruned after a ladder changes.
- Backtests feed 30 candle closes; live feeds 30 tick prices.

## Goals / Non-Goals

**Goals**
- Make priority depend on distance from the market, not price direction.
- Make paper/backtest fills realistic when a candle or tick gaps through a limit.
- Reject configurations at creation that live trading would reject after rounding.
- Make `initial_spacing` a real, validated, clamped setting.
- Remove stale disarmed levels that can never be reconciled.
- Record the orphaned-inventory and volatility-window decisions in specs/docs.

**Non-Goals**
- Auto-rearming canceled take-profit orders.
- A candle-backed live volatility window.
- Full venue tick/step simulation inside paper/backtest order books.
- Changing risk limits, live gates, queueing, or backtest caps.

## Decisions

1. **Nearest-first ordering lives in one strategy helper.** `SortOrderIntents` sorts a copy by `abs(price - market)`, then by lower price for ties. Runtime passes the current mark/fill price; backtest passes the candle close. This keeps both modes deterministic and identical.

2. **Gap fills use the better price.** For a buy, fill at `min(limit, market)`; for a sell, `max(limit, market)`. A fill still requires the market to cross the limit. Fees and inventory use the fill price. The PHP paper adapter and Go paperfill/backtest books share this rule. This is conservative for normal fills and realistic for gaps.

3. **Plan validation simulates live rounding.** The Go plan truncates each level price and order quantity to the market's price/amount precision, then rejects zero values and values below the minimum amount/notional. This is an approximation of venue tick/step rules, not a replacement for them; it prevents the common pass-paper-fail-live case. Martingale's larger sizes cannot introduce a new *minimum* violation because they are at least the base size.

4. **`initial_spacing` is wired, not removed.** The form, preview, create, update, and backtest prefill accept an optional positive value. Go clamps it to `[min_spacing, max_spacing]`, including the fewer-than-two-prices branch. When absent, `min_spacing` is used. Existing stored values keep working.

5. **Prune only unreconcilable disarms.** On a recalculated/recentered ladder, remove a disarmed entry when its recorded pair price is absent from the new ladder. Entries with no pair stay disarmed. This fixes stale blocking without re-arming inventory merely because a level has no open order.

6. **Orphaned inventory remains a documented choice.** A canceled take-profit order leaves the source level disarmed; inventory exits through native sell levels or a strategy reset. Auto-rearming could duplicate sells or realize losses, so it is not part of this change.

7. **Volatility window remains an observation count.** Specs and docs state that backtests use 30 candle closes while live uses 30 tick prices. A true parity fix needs a bot timeframe and a candle-backed window.

## Risks / Trade-offs

- Gap-aware fills change historical backtest results and paper P&L; golden fixtures must be reviewed, not bulk-regenerated.
- Rounding at plan time uses precision, not the venue's exact tick/step size, so it can reject or accept at the margin. Live still performs the authoritative check.
- Pruning a disarm can allow a new entry at a price where inventory is still held. This is intentional: the paired exit no longer exists, so the level is reconciled.
- Adding an optional field changes the form and request surface but stays backward compatible.

## Migration Plan

No database migration. Deploy code, specs, and docs together. Existing bots keep their configuration; `initial_spacing` is optional and clamped. Roll back by image revert.

## Open Questions

None.
