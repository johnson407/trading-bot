# Design

## Atomic sell reservation

`placeIntent()` resolves the grid level and applies trading-rule rounding, then opens a database transaction that locks the bot row (`Bot::lockForUpdate()`). Inside the lock it runs the risk check, the existing-order lookup, and `OrderPlacementService::request()`, returning a small result describing whether the intent was blocked, matched an existing order, or created a pending order. The exchange call and the transition to open happen after the transaction commits.

The pending order row is the reservation: `RiskGuard` already subtracts open and pending sell quantities from available base inventory, so a competing tick that acquires the lock afterwards sees the reservation and defers. A network failure after commit leaves the order pending, which the existing sync and reconciliation paths adopt by client order id.

## Fee semantics

`ExchangeOrder::$fee` is nullable. `null` means the exchange did not report a fee and the bot's configured rate applies; an explicit zero means the exchange charged nothing and no fallback applies; a negative value is a rebate. `FeeResolver::forFill()` subtracts the fee already recorded on the order from the cumulative exchange fee without clamping, so partial fills are not double-counted and rebates reduce fees paid.

## Shared-account skip log

`BalanceSynchronizer` gates the skip activity on `Cache::add('balance-sync-skipped:{bot}:{utc-date}', true, endOfDay)`, so the first skip of the day is recorded and later skips in the same day stay quiet.

## Risks

- Locking per intent adds a short critical section per order. It covers only the check-and-create window; exchange calls stay outside.
- Fee semantics rely on exchanges actually omitting the fee key when unknown; a reported zero is now authoritative.
