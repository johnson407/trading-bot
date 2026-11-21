# Tasks

## 1. Atomic sell reservation

- [x] 1.1 Add a failing test asserting the local pending order exists when the exchange placement callback runs, and that a second sell against the same base is deferred.
- [x] 1.2 Wrap the risk check, existing-order lookup, and order creation in a bot-row lock transaction; keep the exchange call outside it.
- [x] 1.3 Verify the reservation tests and the existing grid, exposure, and order suites.

## 2. Fee semantics

- [x] 2.1 Add failing tests for an explicit zero fee and a negative rebate.
- [x] 2.2 Make the exchange fee nullable, keep unreported fees distinct from zero, and remove rebate clamping.
- [x] 2.3 Verify the live fee, sync, and reconcile suites.

## 3. Shared-account skip log

- [x] 3.1 Add a failing test that two syncs in the same day record one skip activity.
- [x] 3.2 Gate the skip activity on a per-bot per-day cache key.
- [x] 3.3 Verify the balance sync suite.

## 4. Verification

- [x] 4.1 Full Docker suite with no regressions.
- [x] 4.2 PHPStan level 5 clean.
- [x] 4.3 Coverage at or above the previous 92.1%.
- [x] 4.4 Update README counts and affected docs.
