# Design

## Context

See `proposal.md` - Why. The daily-loss check runs inside `Runtime.dailyLoss` in `engine/internal/runtime/tick.go`. It currently reads a bot row and an account row through a `UNION ALL ... LIMIT 1` query, applies `COALESCE` against hard-coded defaults before the account row is considered, discards the query error, and selects two unused columns. The account day-start/baseline query also discards its error. The limit values default to `100` for daily loss, `1000` for position notional, and `20` for open orders in `runtime.go`.

## Goals / Non-Goals

**Goals:**

- Resolve the daily-loss limit from the bot override, then the account row, then the default, per column.
- Fail closed on unreadable risk-limit or baseline data with a clear persisted error.
- Keep the change inside the Go engine, with no schema or UI change.
- Cover missing rows, NULL columns, and query failures with tests.

**Non-Goals:**

- Changing position-notional or open-order limit loading in the order-placement paths.
- Changing the default limit values, configuration keys, or the live-trading gate.
- Auto-resuming bots paused by the existing consecutive-failure policy.
- Changing the account-wide loss aggregation queries beyond error handling for the limit/baseline reads.

## Decisions

### Resolve per column with scalar subqueries

Use one query whose `COALESCE` arguments are scalar subqueries: the first non-NULL bot-scope `daily_loss_limit`, then the first non-NULL account-scope limit, then the default parameter. This makes fallback per column and removes arbitrary row selection from `UNION ALL ... LIMIT 1`. A missing row naturally yields the next level because a scalar subquery returns NULL, so "no row" is not an error.

Alternative considered: keep the union and order rows, then merge columns in Go. Rejected because it needs two round trips or extra row-shape code and is easier to get wrong when only some columns are NULL.

### Propagate limit and baseline read errors through the tick failure path

Change `dailyLoss` to return an error alongside its existing values. The `tickBot` call site sends that error to the existing `tickFailure` path, which persists `last_error`, increments `consecutive_failures`, and pauses after the existing threshold. This is the project's established fail-closed path and avoids inventing a separate pause policy. Treat `pgx.ErrNoRows` on the account baseline query as "no account row" and use defaults; every other error is returned.

Alternative considered: pause immediately on the first query error. Rejected because transient database errors would pause every bot permanently, while the existing failure-threshold path already stops trading after repeated failures. Alternative considered: log and continue with defaults. Rejected because that is the reported bug.

### Keep placement-path limit queries out of scope

The daily-loss query stops selecting `max_position_notional` and `max_open_orders`; those columns are still read by the order-placement paths, which are a separate concern. This change does not alter order placement behavior beyond the daily-loss gate running before it.

### Test through observable tick behavior

Add opt-in PostgreSQL integration tests under `engine/internal/runtime` that create bots and risk-limit rows, then assert status and `last_error` after `TickBot`:

- no limit rows plus a loss above the default pauses the bot;
- an account limit with a NULL bot-scope override lets the bot continue;
- a bot override with a lower limit pauses before the account limit would;
- a temporarily renamed `risk_limits` table makes the tick fail closed with an error and no orders;
- a temporarily renamed `day_start_realized_pnl` column makes the account-baseline read fail closed with an error and no orders.

The rename tests restore the table/column in `t.Cleanup`, recover leftovers from an interrupted prior run, and the package's tests run sequentially, so they do not overlap another integration test.

## Risks / Trade-offs

- **Fail-closed pauses after repeated database errors** -> The existing three-failure threshold limits the blast radius, and pausing is safer than trading against the wrong limit. A single transient error only records `last_error`.
- **Renaming `risk_limits` or its baseline column in a test can leak if cleanup does not run** -> Register cleanup immediately after each rename, recover a leftover rename at the start of the test, and do not run those tests in parallel.
- **Bot-scope rows are legacy after the 2026-11-21 migration** -> The fallback remains correct for any row that survives or is reintroduced; NULL columns no longer shadow account limits.
- **Extra query subqueries may be marginally slower** -> The query runs once per tick and uses the existing `bot_id` and `user_id` indexes.
- **Accounts without an account-scope row have nowhere to persist the account baseline** -> The per-bot default still applies, but the account-wide default can only cover unrealized loss until a row exists. Normal bot creation creates the row (`CreateBot` calls `RiskLimitService::forUser()`); direct-insert or legacy accounts remain a known gap. Closing it would require lazily creating the row or another baseline store, which is a separate change.

## Migration Plan

No data migration. Deploy the engine image with the normal release process. Rollback is the previous engine image; no schema or stored state changes are involved.
