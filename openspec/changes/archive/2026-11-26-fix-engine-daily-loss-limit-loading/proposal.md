# Proposal

## Why

The Go engine's daily-loss guard discards errors from its `risk_limits` queries and silently continues with hard-coded defaults. A bot-scope row with a NULL `daily_loss_limit` also resolves to the built-in default instead of the account limit, so a database or schema problem can weaken or change a user's configured risk limit without any signal.

## What Changes

- Resolve the effective daily-loss limit per column: explicit bot override first, then the account-scope row, then the built-in default.
- Fail closed when the daily-loss limit or the account day-start/baseline cannot be loaded: record a clear `last_error` and do not place orders, instead of trading on defaults.
- Stop selecting the unused position and open-order columns in the daily-loss query.
- Add engine tests for a missing `risk_limits` row, a NULL bot-override column, and a `risk_limits` query failure.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `risk-controls`: The daily-loss requirement now requires error-free limit resolution with explicit bot, account, then default fallback, and fail-closed behavior when limit or baseline data cannot be read.

## Impact

- Affected code: `engine/internal/runtime/tick.go` (`dailyLoss` and the daily-loss call site in `tickBot`), plus engine runtime integration tests.
- No database migration, API, or frontend change is required.
- The live-trading gate and order-placement risk checks are unchanged.
