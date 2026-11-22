# Equity analytics

The dashboard keeps a rolling, account-level history of equity so you can see whether the account is growing and how deep its drawdowns have been. It is not a per-bot performance report, and it does not reconstruct history that predates the feature.

## Capture

`analytics:capture-equity` runs every configured interval and writes one row per owner to `equity_snapshots`.

| Setting | Default | Environment |
| --- | --- | --- |
| Capture interval | 5 minutes | `ANALYTICS_CAPTURE_INTERVAL_MINUTES` |
| Retention | 90 days | `ANALYTICS_RETENTION_DAYS` |
| Default chart range | 30 days | `ANALYTICS_DEFAULT_RANGE_DAYS` |
| Chart point cap | 500 | `ANALYTICS_CHART_POINTS` |

The schedule uses `withoutOverlapping`, so a slow capture does not stack runs. Only users with at least one bot are captured. A failure for one account is logged and skipped; other accounts are still captured.

### Bucket idempotency

`captured_at` is floored to the start of the current capture interval. For example, with a 5-minute interval, a run at 12:07:30 writes 12:05:00. Re-running in the same bucket updates that row rather than adding another, so the unique `(user_id, captured_at)` constraint and `updateOrCreate` make capture safe to retry.

## Equity definition

For each bot:

```
quote value = quote_inventory
base value  = base_inventory * mark price
equity      = quote value + base value
```

The mark price is `settings.mark_price` when positive, then `average_entry_price` when positive, then zero. A bot with neither mark nor average entry contributes only its quote inventory. A quote-only bot contributes only its quote inventory.

Unrealized P&L is `(mark price - average_entry_price) * base_inventory` when base inventory is positive and a mark exists; otherwise it is zero. Realized P&L, fees, and the bot count are stored alongside equity.

All arithmetic uses the project's decimal helper, not floating point. Snapshot columns store 8 decimal places while bot balances can carry up to 16, so captured values are rounded to 8 decimals and `equity` equals the stored quote value plus the stored base value.

### Mixed quote currencies

Account totals add quote values across bots without currency conversion. The account-level figure therefore assumes every bot trades against the same quote unit. A mixed `BTC/USDT` and `BTC/USDC` account is not converted; treat the total as informational until a conversion layer exists.

## History is forward-only

Snapshots begin when the feature is deployed. There is no backfill from fills or inventory ledger entries. A newly deployed dashboard shows an empty "collecting" state until at least two captures exist.

The historical tables remain the source of truth for trades and balances; analytics only deletes its own snapshots during retention.

## Retention

`analytics:prune-equity` runs daily at 03:15 UTC and deletes snapshots older than the retention window. Pass `--days=` to override the configured default for one run:

```bash
php artisan analytics:prune-equity --days=30
```

Invalid or non-positive values fail without deleting anything. Pruning never touches fills, inventory ledger entries, risk events, activity logs, orders, or alerts.

## Dashboard

`/dashboard` adds an equity section driven by the selected range: 7, 30, or 90 days, defaulting to 30. It shows period return, peak equity, and maximum drawdown, then downsamples the curve to the configured point cap for rendering.

Metrics are computed from the full range before downsampling. The chart refreshes on its own one-minute partial reload; the existing five-second summary poll is unchanged.

### Metric formulas

For a range with snapshots ordered oldest to newest:

- **Period return** = `(end equity - start equity) / start equity * 100`, rounded to two decimals. It is unavailable when the start equity is zero or negative, rather than showing a fabricated percentage.
- **Peak equity** = the highest equity in the full range.
- **Maximum drawdown** = the largest peak-to-trough decline in the full range, as a positive percentage: for each point, `(running peak - equity) / running peak * 100`, taking the maximum.

An empty range has no history and shows the empty state. A single snapshot is shown as collecting, renders its point on the chart, and has no metrics; the page does not invent zeros for return, peak, or drawdown. Metrics always use every snapshot in the range, even when the chart is downsampled to the point cap.

## Tests

| Behavior | Test |
| --- | --- |
| Snapshot sums and bucket idempotency | `EquitySnapshotterTest` |
| Capture command | `EquityCaptureCommandTest` |
| Retention and source preservation | `EquityRetentionTest` |
| Period metrics and downsampling | `EquityHistoryTest` |
| Dashboard range and chart | `DashboardTest`, `EquityAnalyticsIntegrationTest` |
| Capture-to-dashboard flow | `EquityAnalyticsIntegrationTest` |
