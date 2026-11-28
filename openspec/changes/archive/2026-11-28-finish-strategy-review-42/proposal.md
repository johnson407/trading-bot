## Why

The strategy review left seven tracking items open in issue #42. They cover order priority under short capital, orphaned inventory, gap fills, exchange-rule drift between modes, dead dynamic-grid configuration, stale disarmed levels, and the volatility window. The first five can be corrected or made explicit without changing the live safety model.

## What Changes

- Place order intents nearest to the market first in paper, live, and backtest placement, so short capital and `max_open_orders` do not favour the farthest buys.
- Fill paper and backtest limit orders at the better of the limit and market price when the market gaps through the limit.
- Extend creation-time plan validation to simulate live price/amount rounding and reject values that round to zero or fall below exchange minima.
- Wire `initial_spacing` into the dynamic-grid create/edit/preview form and clamp it like every other candidate spacing.
- Prune disarmed level entries whose paired price is no longer in a recalculated ladder, for trailing and dynamic grids.
- Document that canceled take-profit orders do not auto-rearm their source level, and that the 30-observation volatility window is candle-based in backtests and tick-based live.

## Out of Scope

- Automatically re-arming canceled take-profit levels or otherwise rewriting orphaned inventory.
- Time-based or candle-backed volatility windows, which need a bot timeframe and a separate data path.
- Applying full venue tick/step rules inside paper and backtest fills; creation-time simulation is the gate.
- The already-completed `spacing_type=dynamic` rejection for trailing and martingale.

## Capabilities

### Modified Capabilities
- `strategy-engine`: dynamic initial spacing clamping and disarmed-level pruning.
- `grid-trading`: nearest-to-market order priority and the orphaned-inventory decision.
- `backtesting`: gap-aware fills, placement priority, and the volatility-window limitation.
- `bot-provisioning`: exchange-rule rounding at plan time and the `initial_spacing` field.

## Impact

- Go: `engine/internal/strategy/grid.go`, `dynamic.go`, `trailing.go`, intent ordering helper, runtime `tick.go`/`live.go`, backtest `engine.go`/`orderbook.go`, `paperfill`.
- PHP/Vue: dynamic-grid form state, request validation and configuration building, paper exchange fills, backtest prefill.
- Specs/docs and golden fixtures; backtest results and paper fills change where a limit gap was previously filled at the limit.
- No database migration. Existing bots keep their stored configuration; `initial_spacing` becomes editable and clamped.
