# backtesting Specification

## Purpose

Lets a user replay an existing bot's strategy against historical candles, review directional performance, and compare saved runs before committing capital.

## Requirements

### Requirement: Backtest from an existing bot

The system SHALL let a user start a backtest from a bot they own and SHALL snapshot the bot, strategy, effective risk limits, protective exit rules, and period at creation. Laravel SHALL store the immutable snapshot, insert a queued run, and publish a `backtest.run` command. The snapshot SHALL remain readable after the source bot is deleted. The Go engine SHALL execute the queued run.

#### Scenario: Create a run from an owned bot

- **WHEN** a signed-in user starts a backtest from one of their bots
- **THEN** the system stores an immutable configuration snapshot and queues the run

#### Scenario: Another user's bot is rejected

- **WHEN** a user attempts to start a backtest from a bot they do not own
- **THEN** the request is denied and no run is created

#### Scenario: Later bot edits do not change a run

- **WHEN** a user edits the source bot after a run was created
- **THEN** the existing run continues to use its original snapshot

#### Scenario: Exit rules are snapshotted

- **WHEN** a run is created from a bot with protective exit rules
- **THEN** the run stores those rules and later edits to the bot do not change them

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

The Go engine SHALL replay the snapshotted strategy through the same first-tick and subsequent-tick lifecycle used for live execution — initialization inside the normal tick path, tick, fill, cancel, and stop — in a side-effect-free simulation that does not write bot, order, fill, risk, or activity records. Laravel SHALL NOT replay strategies after cutover.

#### Scenario: Strategy lifecycle is honored

- **WHEN** the engine replays a candle sequence
- **THEN** it evaluates the first candle through the strategy's normal tick path with new state, evaluates each subsequent candle, and reports each fill and cancellation to the strategy

#### Scenario: First candle initializes and ticks

- **WHEN** the engine processes the first candle
- **THEN** it calls the strategy's normal tick with a new state so initialization and initial order generation happen in one decision, and it does not call initialization separately in a way that suppresses first-candle orders

#### Scenario: Dynamic first candle places its ladder

- **WHEN** a dynamic-grid replay starts with a recalculation interval longer than one candle
- **THEN** the first candle places the initial ladder without waiting for the interval to elapse

#### Scenario: Grid replacement re-places the new ladder

- **WHEN** a candle causes a grid replacement that cancels the open simulated orders
- **THEN** the new ladder receives orders for its armed levels, including levels whose prices were also present in the replaced ladder

#### Scenario: A second evaluation that returns no orders does not empty the ladder

- **WHEN** the engine re-evaluates a replaced grid and the second evaluation returns no orders
- **THEN** the orders already computed for that candle's new ladder are not discarded

#### Scenario: Multiple fills are processed sequentially

- **WHEN** more than one order fills on the same candle
- **THEN** each fill updates the portfolio and is reported to the strategy before the next fill is processed

#### Scenario: Same-candle gap may defer an opposite order

- **WHEN** more than one buy order fills on the same candle and the adjacent higher level is still open
- **THEN** the opposite sell for that fill is not placed during that candle, and the filled level remains disarmed for the rest of the replay unless a paired fill re-arms it

#### Scenario: Simulation is isolated from live data

- **WHEN** a backtest runs
- **THEN** no live bot, order, fill, risk, or activity record is created or modified

### Requirement: Close-only fill model

The system SHALL simulate fills at the candle close only: a buy limit fills when the close is at or below the limit, a sell limit fills when the close is at or above the limit, the fill price SHALL be the better of the limit and the candle close, and the full remaining quantity SHALL fill. A gap through a limit therefore fills at the candle close rather than at the stale limit.

#### Scenario: Buy limit is crossed

- **WHEN** a candle close is at or below an open buy limit
- **THEN** the order fills in full at the lower of its limit price and the candle close

#### Scenario: Sell limit is crossed

- **WHEN** a candle close is at or above an open sell limit
- **THEN** the order fills in full at the higher of its limit price and the candle close

#### Scenario: Limit is not crossed

- **WHEN** the candle close does not cross an open order's limit
- **THEN** the order remains open

#### Scenario: Same-candle placements wait

- **WHEN** a strategy places an order during a candle
- **THEN** that order is not eligible to fill until a later candle

#### Scenario: Market gaps through a buy limit

- **WHEN** a candle closes below an open buy limit
- **THEN** the fill uses the candle close, not the higher limit

#### Scenario: Market gaps through a sell limit

- **WHEN** a candle closes above an open sell limit
- **THEN** the fill uses the candle close, not the lower limit

### Requirement: Replay order priority

When more strategy intents exist than capital or the replay open-order limit can accept, the system SHALL evaluate intents nearest to the candle close first on both sides, breaking equal distances by lower price, and SHALL place orders in that order.

#### Scenario: Nearer orders are placed first

- **WHEN** a replay candle produces more intents than the open-order limit allows
- **THEN** the intents closest to the candle close are placed and the farther intents remain unplaced

### Requirement: Volatility observation window

The replay SHALL derive dynamic-grid volatility from at most the last 30 candle closes. The engine SHALL document that live trading uses the last 30 tick prices instead, so spacing is measured over different time horizons until a candle-backed live window is introduced.

#### Scenario: Replay uses candle closes

- **WHEN** a dynamic-grid backtest has more than 30 prior candles
- **THEN** only the most recent 30 closes affect the candidate spacing

### Requirement: Backtest fees

The system SHALL charge each simulated fill the bot's configured fee rate applied to the fill notional in the quote currency, and SHALL include fees in realized profit and loss and in the reported fees paid.

#### Scenario: Fee is charged per fill

- **WHEN** a simulated fill occurs
- **THEN** the fee equals the fill price multiplied by the filled quantity and the configured fee rate, and is deducted from profit and loss

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

### Requirement: Replay protective exits

The system SHALL enforce the run's snapshotted protective exit rules at each candle close, using the same precedence as live execution: stop-loss, then take-profit, then range. A trigger SHALL cancel open simulated orders and end the run with a terminal protective-exit outcome. The run SHALL NOT resume.

#### Scenario: Stop-loss ends the replay

- **WHEN** a candle close is at or below the snapshotted stop-loss threshold while the simulated portfolio holds base inventory
- **THEN** open simulated orders are canceled and the run ends with the `stop_loss` outcome

#### Scenario: Take-profit ends the replay

- **WHEN** a candle close is at or above the snapshotted take-profit threshold while the simulated portfolio holds base inventory
- **THEN** open simulated orders are canceled and the run ends with the `take_profit` outcome

#### Scenario: Range exit ends the replay

- **WHEN** a candle close is at or below the snapshotted range low or at or above the snapshotted range high
- **THEN** open simulated orders are canceled and the run ends with the `range_exit` outcome

#### Scenario: Stop-loss takes precedence

- **WHEN** a candle close breaches both the stop-loss threshold and the range low
- **THEN** the run ends with the `stop_loss` outcome rather than the range outcome

#### Scenario: Rules remain inside their thresholds

- **WHEN** a candle close is inside every enabled threshold
- **THEN** the replay continues without an exit outcome

#### Scenario: Disabled rules are ignored

- **WHEN** the snapshot has no configured exit rules, or a rule is blank
- **THEN** that rule does not end the run

### Requirement: Run outcomes and metrics

The system SHALL record a run outcome of range end, risk pause, strategy stop, stop-loss, take-profit, or range exit, and SHALL report final equity, net, realized, and unrealized profit and loss, fees, fill counts, maximum drawdown, and an equity curve of at most 1,000 sampled points.

#### Scenario: Completed run reports metrics

- **WHEN** a run finishes
- **THEN** the stored result includes the outcome, profit and loss figures, fees, fill counts, and maximum drawdown

#### Scenario: Equity curve is bounded

- **WHEN** a run processes more candles than the sampling cap
- **THEN** the stored equity curve is downsampled to at most 1,000 points while preserving its first and last samples

#### Scenario: Protective exit outcome is recorded

- **WHEN** a replay ends because a protective exit rule triggers
- **THEN** the stored result reports the matching `stop_loss`, `take_profit`, or `range_exit` outcome

### Requirement: Run lifecycle and failure reporting

The system SHALL expose pending, running, completed, and failed run states, record progress, and ignore a duplicate command for the same run. A failed run SHALL have a concise non-secret error. The engine SHALL write status, metrics, and fills, and SHALL fail a run whose worker disappears or exceeds the timeout. Laravel SHALL display results and SHALL NOT schedule `backtests:fail-stale` after cutover.

#### Scenario: Successful run transitions

- **WHEN** a queued run begins and finishes normally
- **THEN** it transitions from pending to running to completed

#### Scenario: Duplicate job is ignored

- **WHEN** a second command or job attempts to execute an already-running or completed run
- **THEN** it does not process the run again

#### Scenario: Run fails

- **WHEN** candle retrieval or simulation raises an error
- **THEN** the run is marked failed with a concise message and the error is logged

#### Scenario: Stale run is failed by the engine

- **WHEN** a backtest run's worker disappears or exceeds the documented timeout
- **THEN** the engine marks that run failed with a concise message

### Requirement: Backtest dashboard

The system SHALL provide authenticated, owner-scoped pages to create, list, inspect, and delete backtest runs. Creation SHALL accept a bot, timeframe, and past date range, reject a range yielding more than 200,000 candles, and list and detail pages SHALL refresh while a run is active. The dashboard SHALL submit runs to the engine and read results from Postgres; it SHALL NOT execute the replay in Laravel.

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

### Requirement: Create paper bot from backtest results

Completed backtest run detail pages and completed sweep run rows SHALL offer a create-paper-bot action when the run is usable by the viewer. The run and sweep run resources SHALL expose a `can_create_bot` flag that matches the action's availability. The action SHALL open `bots.create` with the run ID as `source_backtest_run_id`.

#### Scenario: Completed run exposes the action
- **WHEN** the owner views a completed run with a usable snapshot and available strategy
- **THEN** the run detail page offers a create-paper-bot action targeting that run

#### Scenario: Incomplete or failed run has no action
- **WHEN** a run is pending, running, or failed
- **THEN** its detail page does not offer the create-paper-bot action

#### Scenario: Unsupported snapshot or exchange has no action
- **WHEN** a completed run has an unusable snapshot, an unavailable strategy, a disallowed exchange, or an invalid symbol
- **THEN** `can_create_bot` is false and the action is not offered

#### Scenario: Sweep run row exposes the action
- **WHEN** the owner views a completed sweep run row with a usable source
- **THEN** the row offers a create-paper-bot action targeting that specific run

#### Scenario: Incomplete sweep row has no action
- **WHEN** a sweep run is pending, running, or failed
- **THEN** its row does not offer the create-paper-bot action

#### Scenario: Another user's run is not exposed
- **WHEN** a run belongs to another user
- **THEN** `can_create_bot` is false and no action is offered

#### Scenario: Resource flags match the action
- **WHEN** the run and sweep run resources are rendered
- **THEN** `can_create_bot` is true exactly when the action is available

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

### Requirement: Parameter sweep creation

The system SHALL create a parameter sweep that runs one backtest for every combination of selected center prices, spacing values, and level counts against one bot, timeframe, and date range. Laravel SHALL create the sweep and queued runs and publish a `backtest.sweep` command; the Go engine SHALL execute the runs. A sweep SHALL create between 2 and the configured maximum runs and SHALL apply only those three parameters over each run's snapshot.

#### Scenario: Creates the cartesian product

- **WHEN** an owner submits two center prices, two spacing values, and two level counts for one bot
- **THEN** the system creates eight pending backtest runs grouped under one sweep

#### Scenario: Overrides only swept fields

- **WHEN** a sweep run is created from a bot whose strategy sets different values
- **THEN** the run snapshot uses the swept center price, spacing value, and level count while preserving every other strategy, risk, exit, and period field

#### Scenario: Rejects a set with one combination

- **WHEN** an owner submits values that produce only one run
- **THEN** the request is rejected without creating a sweep or any run

### Requirement: Sweep validation and limits
A sweep SHALL use a grid strategy, positive decimal center prices and spacing values, integer level counts from 1 to 50, and a total run count no greater than the configured sweep maximum. Duplicate values within one dimension SHALL be rejected. The sweep and its runs SHALL belong to the authenticated owner.

#### Scenario: Accepts a valid grid sweep
- **WHEN** an owner submits unique positive values and level counts within range for a grid bot
- **THEN** the sweep and its runs are created for that owner

#### Scenario: Rejects an oversized sweep
- **WHEN** the cartesian product exceeds the configured maximum
- **THEN** the request is rejected with a validation error and no runs are created

#### Scenario: Rejects a non-grid strategy
- **WHEN** an owner selects a bot whose strategy is not a grid strategy
- **THEN** the request is rejected with a validation error

### Requirement: Sweep grouping and results
Each sweep run SHALL retain its own immutable snapshot, status, progress, end reason, metrics, and equity curve. The sweep SHALL expose its runs with their swept values, allow active runs to be polled, and allow two to four completed runs to use the existing comparison view.

#### Scenario: Groups runs under the sweep
- **WHEN** a sweep is created
- **THEN** every generated run stores the sweep identifier and its swept values and appears on the sweep detail page

#### Scenario: Reports active progress
- **WHEN** some sweep runs are pending or running
- **THEN** the sweep detail response reports their status and progress for polling

#### Scenario: Compares completed runs
- **WHEN** an owner selects two to four completed runs from the same sweep
- **THEN** the existing comparison view accepts them
