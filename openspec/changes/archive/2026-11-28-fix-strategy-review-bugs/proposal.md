# Proposal

## Why

A strategy review found that the dynamic grid silently loses orders in live and backtest runs, that backtests pre-initialize the first candle in a way that suppresses dynamic's initial ladder, and that martingale accepts an impossible maximum size and grows on take-profit sells. These are correctness bugs in money-handling paths: backtest results diverge from live behavior, and a martingale configuration can pass creation and then fail on every fill.

## What Changes

- Dynamic grid SHALL re-place missing orders at armed levels on ticks that are not due for a spacing recalculation, instead of returning levels only.
- Backtesting SHALL run the first candle through the strategy's normal tick path so initialization and initial order generation happen in one decision, matching live execution.
- When a grid replacement cancels all open orders, the new ladder SHALL be re-evaluated and SHALL receive orders for its armed levels, including prices shared by the old and new ladders. The backtest second pass SHALL NOT discard the first pass's orders when the second pass returns none.
- Martingale SHALL reject a configuration where `max_order_quantity` is below `base_order_quantity`, or where the multiplier or maximum increases are invalid, at strategy initialization, before any existing-state tick places orders, and at bot creation/update.
- Martingale SHALL increase size only when a buy entry fills. Every sell fill SHALL reset the current size to the base quantity, the increase count to zero, and the cap flag to false. A take-profit sell SHALL still use the filled buy quantity.
- Trailing and martingale SHALL reject `spacing_type=dynamic`; dynamic spacing is valid only for the dynamic grid.
- Golden strategy and backtest fixtures, specs, and strategy documentation SHALL be updated to the corrected behavior.

Out of scope for this change:

- Order placement priority when capital or the open-order limit is constrained (review item #42a). This will be a separate change using nearest-to-market-first ordering.
- Orphaned inventory, gap fill pricing, exchange-rule enforcement in paper/backtest, `initial_spacing` cleanup, trailing disarmed-level pruning, and volatility-window semantics (review items #42b-e, #42g, #42h). These need product/design decisions and will be handled separately.
- Any live-trading gate, risk-limit, queue/Horizon, or backtest-cap change. No data migration is proposed for existing bots with `max_order_quantity < base_order_quantity`; a newly initialized or resumed bot fails before placing orders until the user corrects it.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `strategy-engine`: dynamic orders are replenished between recalculations; martingale validates its size configuration and only increases on entry fills, resetting after a sell; trailing and martingale reject dynamic spacing.
- `backtesting`: the first candle uses the normal strategy tick path, and a grid replacement re-places the new ladder's orders, including shared prices.

## Impact

- Go engine: `engine/internal/strategy/dynamic.go`, `martingale.go`, `trailing.go`, `engine/internal/backtest/engine.go`, and the related strategy/backtest tests and golden fixtures.
- Laravel: `app/Http/Requests/Concerns/BuildsStrategyConfiguration.php` and Store/Update request feature tests for the martingale base/maximum relationship.
- Specs and docs: `openspec/specs/strategy-engine/spec.md`, `openspec/specs/backtesting/spec.md`, `docs/strategies.md`, `docs/backtesting.md`, and `docs/bot-parameters.md`.
- Existing behavior change: martingale sell fills reset size instead of increasing it; dynamic ticks may place orders that were previously missing; backtest results for dynamic strategies change because the first candle now places its ladder, and martingale backtest final sizing state changes after a sell fill resets to base.
