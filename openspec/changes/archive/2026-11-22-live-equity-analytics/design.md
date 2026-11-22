# Design

## Context

See `proposal.md` for motivation and the delta specs for required behavior. The dashboard currently computes P&L from live bot rows on every request, and no historical equity table exists. `EquityChart.vue` already renders the backtest curve, so the live view can reuse it.

## Goals / Non-Goals

**Goals:**

- Capture a durable, account-level equity history without adding work to the tick hot path.
- Keep history ownership-scoped and independent of bot deletion.
- Show a bounded chart and period metrics on the existing dashboard.
- Keep retention and capture cadence configurable.

**Non-Goals:**

- Per-bot equity curves.
- Backfilling history from fills or the inventory ledger.
- Benchmarking, currency conversion, or tax/export reporting.
- Changing backtest curve behavior.

## Decisions

### Dedicated `equity_snapshots` table

Store snapshots in `equity_snapshots` rather than deriving history from fills. Fields: `user_id`, `captured_at`, `equity`, `quote_value`, `base_value`, `realized_pnl`, `unrealized_pnl`, `fees_paid`, `bot_count`, timestamps. Unique `(user_id, captured_at)`; index `(user_id, captured_at)` and `captured_at`.

`user_id` cascades on user deletion. Snapshots do not reference bots, so deleting a bot preserves the account history. The component values make the total auditable and leave room for later analytics without recomputing old data.

Alternative rejected: reconstruct from `InventoryLedgerEntry` and `Fill`. Fills do not record the mark price at each past moment, so a reconstructed curve would confuse realized movement with later mark changes. A forward-only history is honest and much simpler.

### Capture every five minutes from a scheduled command

`analytics:capture-equity` runs every five minutes with `withoutOverlapping`. It iterates users with at least one bot and computes one account snapshot per user. `captured_at` is floored to the configured interval bucket, and `updateOrCreate` on `(user_id, captured_at)` makes a manual or retried run idempotent within the bucket.

Capture is not added to `BotTickPipeline`: ticks are per second, and snapshot writes at that rate would dwarf the value. Five minutes is fine for a 7/30/90-day chart and keeps storage bounded at roughly 26k rows per user for 90 days.

Per-user failures are logged and skipped so one bad account does not block the rest.

### Equity definition

For each bot: `quote_inventory + base_inventory * mark_price`. The mark comes from `MarkPrice::for`, falling back to the average entry price, then zero. A bot with no base inventory contributes only quote inventory. Summed values use the existing `Decimal` support, not floats.

Account equity assumes all bot quote inventories share one unit of account, matching the dashboard's current aggregate P&L behavior. Mixed quote currencies are not converted in this change.

### Forward-only history

History starts at the first capture. There is no backfill: older fills cannot produce a truthful mark-to-market curve. The dashboard shows an explicit collecting state until at least one snapshot exists.

### Query, downsample, and metrics

`EquityHistory` accepts the owner and a 7/30/90-day range, returns points in chronological order, and computes:

- period return: `(end - start) / start * 100` when start is positive;
- peak equity: maximum point in the range;
- maximum drawdown: largest peak-to-trough decline as a percentage.

Metrics use the full range, then points are downsampled to `config('analytics.chart_points')` by even stride while preserving the first and last point. With fewer than two points, metrics are unavailable rather than zero-filled.

### Dashboard section with independent refresh

The dashboard gets an `Equity history` section above recent activity, using `EquityChart`. It has 7/30/90-day buttons, three metrics, and an empty state. The page keeps the five-second summary poll and adds a separate one-minute poll for `equityHistory`, because snapshots only change every five minutes. Range changes are an Inertia partial reload of `equityHistory` only.

Alternative rejected: a separate analytics page. The account curve belongs beside the current account summary; a separate page adds navigation without adding value at this scope.

### Retention

`analytics:prune-equity` deletes snapshots older than `analytics.retention_days` (default 90) and runs daily at 03:15. It deletes only snapshots; fills, ledger entries, risk events, and activity logs are untouched.

## Risks / Trade-offs

- **Stale or missing mark price** → fall back to average entry, then zero, and count quote-only inventory.
- **Mixed quote currencies summed without conversion** → documented assumption; consistent with the current dashboard.
- **Duplicate captures in one bucket** → unique key plus `updateOrCreate`, and `withoutOverlapping`.
- **Database growth** → five-minute cadence, 90-day default retention, `captured_at` index.
- **Large chart payloads** → range-bounded query and downsampling to a configured point cap.
- **Snapshots only see bucket boundaries, not intra-bucket extremes** → acceptable for a monitoring curve; backtests remain the tool for intrabar analysis.
- **Capture failure for one account** → log and continue; the next bucket retries naturally.

## Migration Plan

1. Add the `equity_snapshots` table and model.
2. Deploy the capture and prune commands and schedules.
3. Deploy the dashboard section.
4. History begins at the first capture; no backfill job runs.
5. Rollback: remove the dashboard section and schedule, then drop the additive table. Existing fills, ledger entries, and bots are unaffected.

## Open Questions

None.
