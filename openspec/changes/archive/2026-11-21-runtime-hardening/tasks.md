# Tasks

## 1. Numerics and trading rules

- [x] 1.1 Add `Number::decimalString` with unit tests for floats, exponent strings and invalid input.
- [x] 1.2 Normalize ticker, order, fee and balance values in `CcxtExchangeClient`.
- [x] 1.3 Round price/quantity to market precision and reject orders below minimum amount or notional with risk events.

## 2. Strategy lifecycle

- [x] 2.1 Invoke `onCancel` for strategy and grid-replacement cancellations.
- [x] 2.2 Honor `StrategyDecision::stop` through the standard pause-and-cancel procedure.
- [x] 2.3 Pause a bot whose persisted strategy state is missing after a completed tick.

## 3. Market data

- [x] 3.1 Persist a rolling recent-price window and pass it to `MarketSnapshot`.
- [x] 3.2 Re-evaluate the strategy after a grid is recreated so trailing levels are replenished.

## 4. Capacity and startup

- [x] 4.1 Cache exchange clients per exchange and credential.
- [x] 4.2 Add a dispatch guard and raise the stale-tick threshold to 60 seconds.
- [x] 4.3 Reconcile running bots before the scheduler starts.

## 5. Verification

- [x] 5.1 Focused suites for numerics, trading rules, lifecycle, runtime data, startup recovery.
- [x] 5.2 Full Docker suite: 360 passed, 1 skipped, 1716 assertions.
- [x] 5.3 PHPStan level 5 with Larastan: no errors.
- [x] 5.4 Domain coverage: 92.1% with PCOV.
- [x] 5.5 Update `README.md`, `docs/runtime.md`, `docs/strategies.md`, `docs/exchanges.md`.
