# Proposal

## Why

The bot can run four strategies, but there is no way to judge a configuration
against past market conditions before committing capital. Users must either run
paper bots for days or go live and learn from losses. That leaves the strategy
engine's main value — comparing configurations — untested. A saved, replayable
backtest gives directional evidence in minutes and makes future strategy work
measurable.

## What Changes

- Add historical OHLCV retrieval from the bot's exchange through public CCXT
  endpoints, cached per exchange, symbol, timeframe, and candle.
- Add a deterministic, in-memory replay engine that reuses the existing
  `StrategyInterface` strategies and mirrors the bot tick's decision order.
- Use a documented close-only fill model: fill at the limit price when the candle
  close crosses it, full quantity, no slippage and no partial fills.
- Add a flat-start portfolio per run, using the bot's capital allocation, and
  apply the effective station risk limits during replay.
- Persist backtest runs with an immutable configuration snapshot, status,
  progress, summary metrics, a downsampled equity curve, and simulated fills.
- Execute runs asynchronously on the existing queue with bounded runtime and
  visible progress and failure states.
- Add dashboard pages to create a run from an existing bot, list runs, inspect a
  run, and compare two to four compatible runs with overlaid normalized equity
  curves. Add a Backtests navigation entry.
- Out of scope: tick or order-book simulation, slippage, partial fills, parameter
  sweeps, config overrides, standalone backtest configs, CSV import,
  multi-symbol runs, public API, and any change to live or paper trading.

## Capabilities

### New Capabilities

- `backtesting`: historical candle acquisition and caching, deterministic replay
  of a bot configuration, run lifecycle and metrics, and the saved-run comparison
  dashboard surface.

### Modified Capabilities

- `exchange-connectivity`: adds historical OHLCV retrieval through the public
  market-data boundary, with rate limiting, normalized failures, and cacheable
  candle ranges.
- `trading-dashboard`: primary navigation now includes the backtests section.

## Impact

- New domain namespace for candles, replay state, portfolio accounting, simulated
  orders, risk checks, metrics, and results.
- New `market_candles`, `backtest_runs`, and `backtest_fills` tables and models.
- New queued job, controller, policy, form requests, and routes.
- New Inertia/Vue pages plus `chart.js` and `vue-chartjs` as the only new
  dependencies.
- New documentation for the fill model, assumptions, and comparison workflow.
- No changes to `BotTickPipeline`, live/paper execution, risk limits, orders,
  fills, or the existing bot tables.
