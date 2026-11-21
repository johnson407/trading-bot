# backtesting Specification

## Purpose

Lets a user replay an existing bot's strategy against historical candles, review directional performance, and compare saved runs before committing capital.

## Requirements

### Requirement: Backtest from an existing bot

The system SHALL let a user start a backtest from a bot they own and SHALL snapshot the bot, strategy, effective risk limits, and period at creation. The snapshot SHALL be immutable, and a run SHALL remain readable after the source bot is deleted.

#### Scenario: Create a run from an owned bot

- **WHEN** a signed-in user starts a backtest from one of their bots
- **THEN** the system stores an immutable configuration snapshot and queues the run

#### Scenario: Another user's bot is rejected

- **WHEN** a user attempts to start a backtest from a bot they do not own
- **THEN** the request is denied and no run is created

#### Scenario: Later bot edits do not change a run

- **WHEN** a user edits the source bot after a run was created
- **THEN** the existing run continues to use its original snapshot

### Requirement: Historical candle acquisition

The system SHALL retrieve historical OHLCV for the run's exchange, symbol, timeframe, and range through public market-data endpoints, cache candles by exchange, symbol, timeframe, and open time, and simulate only contiguous closed candles. It SHALL fetch missing spans, including interior gaps, before replay.

#### Scenario: Cached range avoids a network fetch

- **WHEN** a run requests a contiguous range of closed candles already cached
- **THEN** the system reads that range from the cache without contacting the exchange

#### Scenario: Missing spans are fetched

- **WHEN** a run requests a range only partly covered by the cache, including an interior gap
- **THEN** the system fetches the missing spans and stores the returned candles

#### Scenario: No historical data

- **WHEN** the exchange returns no candles for the requested range
- **THEN** the run fails with a message stating that no historical data is available

#### Scenario: Forming candle is excluded

- **WHEN** the requested range ends near the present
- **THEN** only candles whose close time is at or before the run's start instant are simulated

#### Scenario: Data gap remains

- **WHEN** the fetched and cached candles still contain an interior gap
- **THEN** the run fails with a message identifying the missing data rather than simulating across the gap

### Requirement: Deterministic strategy replay

The system SHALL replay the snapshotted strategy through the same lifecycle used for live execution — initialization, tick, fill, cancel, and stop — in a side-effect-free simulation that does not write bot, order, fill, risk, or activity records.

#### Scenario: Strategy lifecycle is honored

- **WHEN** the engine replays a candle sequence
- **THEN** it calls initialization once, ticks on each subsequent candle, and reports each fill and cancellation to the strategy

#### Scenario: First candle initializes and ticks

- **WHEN** the engine processes the first candle
- **THEN** it calls initialization and then a tick with the initialized state, and initial grid creation does not trigger an extra tick

#### Scenario: Multiple fills are processed sequentially

- **WHEN** more than one order fills on the same candle
- **THEN** each fill updates the portfolio and is reported to the strategy before the next fill is processed

#### Scenario: Simulation is isolated from live data

- **WHEN** a backtest runs
- **THEN** no live bot, order, fill, risk, or activity record is created or modified

### Requirement: Close-only fill model

The system SHALL simulate fills at the candle close only: a buy limit fills when the close is at or below the limit, a sell limit fills when the close is at or above the limit, the fill price SHALL be the limit, and the full remaining quantity SHALL fill.

#### Scenario: Buy limit is crossed

- **WHEN** a candle close is at or below an open buy limit
- **THEN** the order fills in full at its limit price

#### Scenario: Sell limit is crossed

- **WHEN** a candle close is at or above an open sell limit
- **THEN** the order fills in full at its limit price

#### Scenario: Limit is not crossed

- **WHEN** the candle close does not cross an open order's limit
- **THEN** the order remains open

#### Scenario: Same-candle placements wait

- **WHEN** a strategy places an order during a candle
- **THEN** that order is not eligible to fill until a later candle

### Requirement: Backtest fees

The system SHALL charge each simulated fill the bot's configured fee rate applied to the fill notional in the quote currency, and SHALL include fees in realized profit and loss and in the reported fees paid.

#### Scenario: Fee is charged per fill

- **WHEN** a simulated fill occurs
- **THEN** the fee equals the limit price multiplied by the filled quantity and the configured fee rate, and is deducted from profit and loss

### Requirement: Flat starting state

The system SHALL start every run flat: quote inventory equal to the bot's capital allocation, zero base inventory, zero average entry price, zero realized profit and loss, no open orders, and new strategy state.

#### Scenario: First candle starts flat

- **WHEN** a run processes its first candle
- **THEN** the portfolio holds only the capital allocation in quote currency and no base inventory

### Requirement: Starting capital bounds buys

The system SHALL reject a buy when its notional plus fee exceeds the run's free quote inventory, leaving the order unfilled, and SHALL continue the run.

#### Scenario: Buy exceeds available capital

- **WHEN** a buy would spend more quote currency than the run holds
- **THEN** the order is rejected and the run continues without it

### Requirement: Replay risk limits

The system SHALL enforce the snapshotted maximum position notional, maximum open orders, and daily loss limit during replay. A limit breach SHALL cancel open orders and end the run with a risk-pause outcome; the run SHALL NOT resume automatically.

#### Scenario: Order exceeds an exposure limit

- **WHEN** a strategy order would exceed the maximum position notional or open-order count
- **THEN** the order is rejected and the run continues with its other orders

#### Scenario: Daily loss limit is breached

- **WHEN** the run's loss for a UTC calendar day exceeds the daily loss limit
- **THEN** open orders are canceled and the run ends with a risk-pause outcome

### Requirement: Run outcomes and metrics

The system SHALL record a run outcome of range end, risk pause, or strategy stop, and SHALL report final equity, net, realized, and unrealized profit and loss, fees, fill counts, maximum drawdown, and an equity curve of at most 1,000 sampled points.

#### Scenario: Completed run reports metrics

- **WHEN** a run finishes
- **THEN** the stored result includes the outcome, profit and loss figures, fees, fill counts, and maximum drawdown

#### Scenario: Equity curve is bounded

- **WHEN** a run processes more candles than the sampling cap
- **THEN** the stored equity curve is downsampled to at most 1,000 points while preserving its first and last samples

### Requirement: Run lifecycle and failure reporting

The system SHALL expose pending, running, completed, and failed run states, SHALL record progress while running, SHALL prevent a duplicate job from executing the same run twice, and SHALL report a failed run with a concise non-secret error.

#### Scenario: Successful run transitions

- **WHEN** a queued run begins and finishes normally
- **THEN** it transitions from pending to running to completed

#### Scenario: Duplicate job is ignored

- **WHEN** a second job attempts to execute an already-running or completed run
- **THEN** it does not process the run again

#### Scenario: Run fails

- **WHEN** candle retrieval or simulation raises an error
- **THEN** the run is marked failed with a concise message and the error is logged

### Requirement: Backtest dashboard

The system SHALL provide authenticated, owner-scoped pages to create, list, inspect, and delete backtest runs. Creation SHALL accept a bot, timeframe, and past date range, reject a range yielding more than 200,000 candles, and list and detail pages SHALL refresh while a run is active.

#### Scenario: Create a run from the dashboard

- **WHEN** a user submits a bot, timeframe, and valid past range
- **THEN** the run is created and queued, and the page reports success

#### Scenario: Oversized range is rejected

- **WHEN** the selected range would yield more than 200,000 candles
- **THEN** creation fails with a field-level validation error

#### Scenario: Other users cannot view a run

- **WHEN** a user requests a run they do not own
- **THEN** the request is denied

#### Scenario: Active runs refresh

- **WHEN** a pending or running run is visible on the list or detail page
- **THEN** the page refreshes to show current status and progress

### Requirement: Run comparison

The system SHALL compare two to four runs that share the same symbol, timeframe, and range, showing their summary metrics and overlaid normalized equity curves, and SHALL reject an incompatible selection.

#### Scenario: Comparable runs are compared

- **WHEN** a user compares two to four runs with matching symbol, timeframe, and range
- **THEN** the page shows a metrics table and overlaid normalized equity curves

#### Scenario: Incompatible runs are rejected

- **WHEN** the selected runs differ in symbol, timeframe, or range
- **THEN** the comparison is rejected with a message explaining the mismatch

### Requirement: Stated assumptions

The system SHALL display the close-only fill policy, flat starting state, capital-bounded buying, omission of exchange precision and minimum checks, and the directional-not-predictive limitation on each run detail page.

#### Scenario: Assumptions are visible

- **WHEN** a user opens a run detail page
- **THEN** the page states the fill policy, starting state, capital bound, precision simplification, and the limitation of the results
