## MODIFIED Requirements

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

## ADDED Requirements

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
