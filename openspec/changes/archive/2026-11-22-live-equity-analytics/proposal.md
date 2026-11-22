# Proposal

## Why

The dashboard shows current realized and unrealized P&L, but keeps no history. A user cannot tell whether an account is trending up, how deep past drawdowns were, or whether a change improved performance. This is the next gap after backtesting, alerts, and protective exits: the system can now protect capital, but it cannot show the account's equity path over time.

## What Changes

- Add a scheduled account-equity snapshot capture that stores total equity, quote value, base value, realized P&L, unrealized P&L, fees paid, and bot count per user at a fixed cadence.
- Store snapshots in a dedicated table with one row per user per capture bucket, owned by the user.
- Add retention pruning with a configurable window, defaulting to 90 days.
- Add a dashboard equity-history section with a 7/30/90-day range selector, the existing equity chart, and summary metrics: period return, peak equity, and maximum drawdown.
- Reuse the existing `EquityChart` component and keep the current five-second summary polling separate from the slower equity refresh.
- **Out of scope:** per-bot equity curves, historical backfill from fills or the inventory ledger, benchmark comparison, tax/export reporting, and changes to backtest analytics.

## Capabilities

### New Capabilities

- `analytics`: account-level equity snapshots, capture cadence, retention, and period metrics.

### Modified Capabilities

- `trading-dashboard`: the dashboard overview gains an equity-history section, range selection, and empty/insufficient-history handling.

## Impact

- New `equity_snapshots` table and `App\Models\EquitySnapshot`.
- New `App\Domain\Analytics\EquitySnapshotter` and `EquityHistory` services.
- New `analytics:capture-equity` and `analytics:prune-equity` commands, scheduled in `routes/console.php`.
- New `config/analytics.php` and `.env.example` entries.
- `DashboardController` loads the selected range and metrics; `Dashboard.vue` renders the new section using `EquityChart.vue`.
- New `docs/analytics.md`; updates to `docs/dashboard.md` and `docs/next-features.md`.
- Tests: model, capture, retention, metrics, dashboard ownership/range/empty-state, and end-to-end capture-to-chart.
