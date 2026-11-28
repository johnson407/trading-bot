# Proposal

## Why

Three follow-up engine defects found in the review of `c39f8bb` leave safety and recovery paths weaker than their specs claim:

- Account daily-loss evaluation can fail open, and a failed UTC day rollover persists a zero baseline that is not recomputed until the next day (issue #33).
- `risk.invalid_order` writes one critical event per skipped order per tick, so a mis-sized grid floods the risk log (issue #34).
- Auto-resume requires `pause_reason_recorded_at = updated_at`, so engine bookkeeping that bumps `updated_at` (cancel-retry or pending-flag cleanup) permanently disqualifies an otherwise eligible stale-tick pause (issue #35).

If nothing changes, a transient account read can understate the account daily loss for a whole UTC day, a mis-sized grid can write a critical event per order per second, and every restart after an engine bookkeeping bump loses startup recovery for the affected bot. These are correctness and operability fixes to existing behavior, not new features.

## What Changes

- **Fail closed on account daily loss (issue #33).** Propagate query, scan, and row errors from the account realized-P&L and account-loss reads instead of treating the loss as zero. Persist the day-rollover baseline only after a successful read, write `day_start` from the engine clock, and retry the rollover on the next tick when it fails. Surface a per-bot daily-loss settings persist failure instead of silently losing the baseline.
- **Aggregate invalid orders (issue #34).** Replace per-order `risk.invalid_order` writes with one aggregated event per bot placement attempt containing the skipped count and a reason breakdown, throttled by the existing five-minute risk-event cooldown and released on write failure.
- **Keep pause provenance current during engine bookkeeping (issue #35).** When an engine write bumps `updated_at` for a paused bot (cancel-retry set/clear, reconcile or strategy-reset clear, reconcile failure), refresh `pause_reason_recorded_at` in the same update. Run pending cleanup before the one-shot auto-resume pass, and exclude bots with pending cleanup flags from auto-resume.
- Keep archived bots included in account daily-loss exposure, as the existing spec and test require; make that intent explicit in code comments and tests.
- Document the existing-data limitation: pauses already invalidated by pre-fix bookkeeping are not repaired automatically, because treating a mismatched recording time as current would remove the rollback signal.
- Update the engine and risk documentation for the new fail-closed and aggregation behavior.

No breaking changes. No database migration, no Laravel behavior change, and no change to risk thresholds, order semantics, or the live-trading gate.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `risk-controls`: daily-loss resolution fails closed for account reads and rollover persistence; invalid-order blocks are aggregated and throttled.
- `bot-runtime`: pause provenance stays current across engine bookkeeping writes; stale-pause auto-resume runs after cleanup and skips bots with pending cleanup.

## Impact

- `engine/internal/runtime/tick.go` — `dailyLoss`, per-bot baseline persistence, paper `placeOrders`, `pause`.
- `engine/internal/runtime/live.go` — live `placeLiveOrders` invalid-order tracking.
- `engine/internal/runtime/runtime.go` — risk-event cooldown helper, auto-resume query/update, `reconcile` ordering, `reconcilePaperState`, `applyDesired`, `resetStrategyState`.
- Engine tests: `daily_loss_internal_test.go` (new), `daily_loss_limit_integration_test.go`, `runtime_integration_test.go`, `risk_event_internal_test.go`, `auto_resume_internal_test.go`, plus placement tests.
- Docs: `docs/engine.md`, `docs/runtime.md`, `docs/risk.md`, `engine/README.md`.
- No schema, API, dependency, or Laravel application changes.
