# Spec Delta

## ADDED Requirements

### Requirement: Historical market data access

The system SHALL retrieve historical OHLCV candles for a supported symbol, timeframe, and time range through public exchange endpoints without credentials. Retrieval SHALL honor exchange rate limits, SHALL normalize exchange failures into the existing error set, and SHALL return candles in ascending open time.

#### Scenario: Fetch candles without credentials

- **WHEN** the system requests historical candles for a symbol and timeframe
- **THEN** the exchange returns public data without an API key or secret

#### Scenario: Range exceeds one request

- **WHEN** the requested range contains more candles than a single exchange request allows
- **THEN** the system retrieves the range in successive requests and returns the combined candles in ascending order

#### Scenario: Exchange failure is normalized

- **WHEN** the exchange rejects or fails a historical data request
- **THEN** the failure is reported as a normalized connectivity error with the exchange name and operation
