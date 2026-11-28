# Design

## Context

See `proposal.md` for motivation. The relevant runtime is in `engine/internal/runtime`:

- `dailyLoss` reads the per-bot baseline from `bots.settings["daily_loss_baseline"]`, reads the account `risk_limits` row, rolls the UTC day by querying all of the user's bots, and then computes account loss from every bot's realized P&L and inventory. Today both `risk_limits` reads are fail-closed; the rollover bots read and the account-loss bots read fall back to zero or skip failed rows, and a rollover with a failed read still writes `day_start_realized_pnl = 0`. `persistSettings` also ignores its write error.
- Paper placement in `tick.go` and live placement in `live.go` emit `risk.invalid_order` per skipped order. `Runtime` already has a mutex-guarded per-bot/per-type cooldown map, `allowRiskEvent`/`releaseRiskEvent`, and `recordPositionLimitSkips`, which emits one event with a skipped count.
- Pause provenance is a cause plus a recording time, and eligibility is `pause_reason_recorded_at = updated_at`. Engine bookkeeping writes that only bump `updated_at` (cancel-retry set/clear, reconcile or strategy-reset clear) therefore make a current stale-tick cause ineligible. `reconcile` currently runs the one-shot auto-resume pass before the cleanup loop.
- Database-backed tests are opt-in with `RUNTIME_TEST_DSN`; the host has no Go toolchain, so tests run through the repository's Docker setup.

## Goals / Non-Goals

**Goals:**

- Every daily-loss read or persistence failure leaves the bot paused for that tick or failing closed, and never destroys the rollover baseline.
- At most one invalid-order event per bot per placement attempt, with a bounded repeat window.
- A stale-tick pause remains eligible across unrelated engine bookkeeping, and recovery runs only after cleanup has had a chance to finish.
- Preserve the existing rollback protection: an older engine that writes `status`, `last_error`, or `updated_at` without provenance still invalidates the cause.

**Non-Goals:**

- Changing which bots count toward account exposure (archived bots stay included) or changing the loss formula.
- Changing the five-minute cooldown length, risk thresholds, order semantics, or live gate.
- Adding schema, Laravel writes, or a new persisted pause marker.
- Replacing the one-shot auto-resume attempt with a permanent retry loop.

## Decisions

### Read daily-loss inputs into helpers that return errors

Extract `accountRealizedPNL(ctx, userID)` to sum `realized_pnl` over the user's bots, and return a wrapped error on query, scan, or `rows.Err()` failure. Use the same pattern in the account-loss loop and `defer rows.Close()` in both. `dailyLoss` propagates those errors, so the existing call site goes through `tickFailure`, which places no orders and records `last_error`; three consecutive failures still pause with the tick-failure cause.

Keep archived bots in both queries. That is the spec's "Archived balances remain in account risk" behavior and is already covered for account loss by `TestAccountDailyLossIncludesArchivedBalances`; add a comment so the missing `deleted_at` filter reads as deliberate. The new rollover-baseline test covers the archived case specifically.

### Persist the rollover only after a successful read

The rollover becomes: read account realized P&L, return on error, then write `day_start` from the engine's UTC clock and the new baseline with a guard `AND (day_start IS NULL OR day_start::date <> $4::date)`, binding `$4` as a `YYYY-MM-DD` string computed with `r.now().UTC().Format("2006-01-02")`. `day_start` is `timestamp without time zone` holding UTC wall time, so a plain `::date` is already UTC-based; do not add `AT TIME ZONE 'UTC'`, because casting the resulting `timestamptz` to `date` re-applies the session timezone. Use the engine clock instead of SQL `now()` so the stored day matches the day used for eligibility.

`RowsAffected() == 0` is success, not failure: another tick may already have rolled the day inside the guard window. Keep the stored baseline and evaluate normally. An `UPDATE` error is a failure and is returned, so `day_start` stays on the old day and the next tick retries. A user with no account-scope row keeps today's behavior: use the built-in default account limit and the current realized total as an in-memory baseline for this tick, with no persisted baseline, as noted by `TestDailyLossUsesDefaultWhenNoRiskLimitRows`; creating that row is out of scope because it changes Laravel-owned data.

Change `persistSettings` to return an error (it has one caller) and return the error from `dailyLoss` when the per-bot baseline cannot be written. Mutate the in-memory baseline before the write (the map must be marshalled to persist it), restore it on failure, and return the write error from `dailyLoss`; because the tick fails and the bot is reloaded next tick, a failed persist cannot leak the new baseline into a later evaluation.

Alternatives: keeping the best-effort write and logging is simpler but leaves the exact fail-open the issue describes. A separate transaction spanning read and update would not help because the failure is a read error, not a partial write.

### Reuse the risk-event cooldown for invalid orders

Add a small `orderSkips` tracker per placement attempt with a `reasons map[string]int` and a total. Live placement records one of `non_positive`, `price_precision`, `quantity_precision`, `rounded_to_zero`, `below_min_amount`, `below_min_notional`; paper placement currently only produces `non_positive`. Both paths record a stable reason per skip instead of writing immediately. A deferred `recordInvalidOrderSkips` emits one `risk.invalid_order` event with `orders_skipped` and `reasons`, guarded by `allowRiskEvent(bot.ID, "risk.invalid_order", cooldown)` and releasing the reservation if the insert fails. Rename the shared constant to `riskEventCooldown`; keep it at five minutes.

Alternatives: one summary per tick without a cooldown still writes once per second for a persistently mis-sized grid. Emitting on persisted state change needs a new column or settings key and a migration. Reusing the existing map keeps the semantics and tests consistent with position limits.

The unarchived `fix-engine-restart-risk-shutdown` change adds a generic blocked-order dedup requirement whose text mentions the reason in the aggregation key. This change aggregates per bot per attempt and carries a per-reason breakdown, which still satisfies "no additional events for the same reason". When that change is archived, its wording should be read as per-bot/per-event-type; this change does not edit another change's artifacts. Because both changes add scenarios, this change names its scenarios uniquely ("invalid-order cooldown") to avoid collisions in the merged spec.

### Keep provenance equality and refresh it in bookkeeping writes

Keep `pause_reason_recorded_at = updated_at` as the eligibility rule because it is the only signal that detects a writer that changed state without updating provenance (the older-engine rollback case). In every engine update that bumps `updated_at` for bookkeeping, add:

```sql
pause_reason_recorded_at = CASE
  WHEN status = 'paused' AND pause_reason IS NOT NULL THEN now()
  ELSE pause_reason_recorded_at
END
```

Apply it to the pause cancel-failure set, the reconcile cancel-failure set, the pending-flag clear, the cancel-retry clear, and the strategy-reset clear. Start, stop, kill-switch, and successful auto-resume keep their current semantics (clear or set provenance explicitly).

Alternatives: a dedicated pause marker column is cleaner conceptually but needs a migration and leaves the rollback signal ambiguous. Comparing only `pause_reason` would ignore stale-tick causes written by an older process and could resurrect them.

### Complete cleanup before the one-shot auto-resume pass

Move the `autoResumePending` call in `reconcile` after the loop that applies desired status and pending flags, but before the stale check. The cleanup loop already selects paused bots with pending cancel-retry, reconcile, or strategy-reset flags, so the same reconcile pass clears them and then the auto-resume pass sees a current cause. Add the three pending-flag predicates to the auto-resume update (defense in depth) and skip candidates in Go when a flag is still set.

Keep auto-resume once per process, but do not let a transient cleanup failure consume the attempt or block unrelated bots: always run the auto-resume pass after cleanup, skip candidates whose pending flag is still set, and restore `autoResumePending` to true when any cleanup apply failed so the next one-second reconcile retries cleanup and then the resume. The blocked bot stays paused and is never resumed with stale order state, while eligible bots with no pending cleanup still resume in the same pass. When no cleanup is pending, behavior is unchanged.

Alternative: keep auto-resume first and wait for cleanup on a later reconcile pass. That leaves the bot paused for at least another second and makes the outcome depend on scheduling, without protecting anything that the flag predicates do not already protect.

### Tests follow the existing split

- Daily loss: a new `daily_loss_internal_test.go` (package `runtime`) calls `dailyLoss` directly. Inject the account P&L read failure by renaming `bots.realized_pnl` with a restore helper that mirrors `restoreRenamedRiskLimitBaselineColumn`; `dailyLoss` uses `r.pool`, so `SET LOCAL search_path` cannot inject it. A dedicated pool with `timezone=Asia/Shanghai` proves the rollover guard compares the stored UTC date, not the session date. `daily_loss_limit_integration_test.go` keeps the tick-level assertions, exercises the rollover write failure and its successful retry with a `BEFORE UPDATE` trigger on `risk_limits`, covers the zero-row already-rolled case, and adds a rollover-baseline test seeded with an old `day_start` and an archived bot.
- Invalid orders: unit tests for aggregation, reason counts, cooldown suppression/expiry, and release on write failure; one paper integration test asserting a single event for several skips; a live placement test with the existing fake exchange.
- Auto-resume: internal tests that set each pending flag and run `Reconcile`, plus a direct helper test proving a pending flag blocks resume, a two-bot test proving a failed cleanup does not block an unrelated eligible bot, and a cleanup-failure test proving `autoResumePending` stays true so the next pass resumes after the failure clears.
- Docs: update `docs/engine.md`, `docs/runtime.md`, `docs/risk.md`, and `engine/README.md`. `docs/risk.md` is a fixture asserted by `tests/Unit/RiskDocsTest.php`; keep `midnight UTC`, the three `risk.defaults.*` keys, `TRADING_LIVE_ENABLED`, and `kill_switch_cancel_failed` in place and run that test.

## Risks / Trade-offs

- [A transient database error now fails the tick instead of silently using zero] -> the bot places no orders, `last_error` is visible, and the next tick retries; repeated failures pause through the existing three-failure path.
- [Rollover uses the engine clock] -> a skewed clock can roll the day early or late; the engine already owns the day boundary and the per-bot baseline uses the same clock.
- [Aggregation hides which individual order failed] -> the event keeps a per-reason count and the total, which is enough to diagnose a mis-sized grid.
- [Refreshing provenance could extend eligibility for a pause the engine keeps touching] -> engine bookkeeping only touches paused bots for cleanup, which is the condition under which the pause is still being worked on; older-engine writes still invalidate.
- [Cleanup-first ordering can delay auto-resume by one reconcile pass] -> the delay is bounded by the one-second scheduler and prevents resuming on stale order state.
- [A cleanup failure delays auto-resume by one or more reconcile passes] -> `autoResumePending` stays true while cleanup fails, so the next one-second pass retries cleanup and resume; the bot remains paused and safe in the meantime.

## Migration Plan

No database change and no Laravel change. Deploy the engine image alone. Rollback is the previous image; refreshed provenance is still valid for the equality rule, so an older engine can resume the same bots. The previous image runs auto-resume before cleanup and has no pending-flag predicates, so complete pending cleanup before rolling back or accept the pre-fix resume behavior for flagged bots. No data conversion is required.

Existing-data limitation: provenance invalidated by pre-fix bookkeeping writes is not repaired. A bot in that state stays paused until an operator starts it explicitly; no data migration is added, because treating every mismatched recording time as current would remove the only rollback signal. Bots that still carry a pending cancel-retry, reconcile, or strategy reset are repaired by the cleanup path on the first pass after deploy.

## Open Questions

None.
