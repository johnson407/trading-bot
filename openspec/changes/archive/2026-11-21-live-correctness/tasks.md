## 1. Fees

- [x] 1.1 Record exchange-reported fees on live fills, prorating cumulative fees across partial fills and falling back to the bot fee rate.
- [x] 1.2 Convert base-asset and third-asset fees correctly in inventory and realized P&L, and cover each case with tests.

## 2. Exposure and sells

- [x] 2.1 Block sells that exceed base inventory not reserved by open sells, without recording a risk event for the expected deferral.
- [x] 2.2 Include remaining open and pending buy notional in the projected position and test the aggregate boundary.

## 3. Risk limits

- [x] 3.1 Apply user-scope limits to bots without an explicit override and remove legacy bot-scope shadow rows by migration.
- [x] 3.2 Keep a per-bot daily-loss baseline in bot settings when no override exists.
- [x] 3.3 Provide the risk-limit edit and update pages with validation and ownership isolation.

## 4. Shared accounts

- [x] 4.1 Skip balance synchronization for accounts shared by multiple live bots and record the reason.
- [x] 4.2 Skip the whole-wallet reconciliation comparison for shared accounts so restarts do not create false conflicts.

## 5. Verification

- [x] 5.1 Full Docker suite passes (343 passed, 1 skipped, 1658 assertions).
- [x] 5.2 PHPStan level 5 with Larastan reports no errors.
- [x] 5.3 Domain coverage remains at or above the configured threshold (91.9%).
