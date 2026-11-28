# Spec Delta

## ADDED Requirements

### Requirement: Backtests use the selected exchange's public candles

A backtest SHALL load candles from the public history of the exchange in its configuration, without credentials, and SHALL reject an exchange that has no registered candle source with a clear validation error. MAX candles SHALL be read from the public MAX candle endpoint, paged within MAX's limits, and returned in ascending open time.

#### Scenario: Backtest on MAX candles

- **WHEN** a backtest is requested for a MAX symbol and time range
- **THEN** candles are fetched from MAX public data in successive requests when the range requires it and the run uses them in ascending order

#### Scenario: Exchange without a candle source

- **WHEN** a backtest is requested for an exchange with no registered candle source
- **THEN** the request is rejected with an error naming the exchange and no data is fetched
