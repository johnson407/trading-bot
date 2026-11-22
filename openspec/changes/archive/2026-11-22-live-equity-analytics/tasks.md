# Tasks

## 1. Schema, model, and configuration

- [x] 1.1 Add the additive `equity_snapshots` migration with owner, capture time, equity components, bot count, unique `(user_id, captured_at)`, and the query/retention indexes; add the `EquitySnapshot` model and factory; verify migration up/down, casts, relations, and factory states in `tests/Feature/EquitySnapshotTest.php`.
- [x] 1.2 Add `config/analytics.php` and `.env.example` entries for capture interval, retention, default range, and chart point cap; verify documented defaults resolve as expected in a config test.

## 2. Capture and retention

- [x] 2.1 Implement `EquitySnapshotter` to sum quote value, mark-to-market base value, realized and unrealized P&L, fees, and bot count per owner into the current interval bucket, with average-entry/zero fallbacks and per-user failure isolation; verify exact sums, fallback behavior, quote-only bots, duplicate buckets, no-bot users, and account isolation in `tests/Feature/EquitySnapshotterTest.php`.
- [x] 2.2 Add `analytics:capture-equity` and schedule it every configured interval with `withoutOverlapping`; verify capture creates one bucket row per owner and a duplicate run does not add a second row in the command test.
- [x] 2.3 Add `analytics:prune-equity` with a validated `--days` option and the configured retention default; verify expired snapshots are deleted while recent snapshots and fills, inventory ledger entries, risk events, and activity logs remain in `tests/Feature/EquityRetentionTest.php`.
- [x] 2.4 Document capture cadence, bucket idempotency, equity definition, fallbacks, forward-only history, retention, and the mixed-quote-currency assumption in `docs/analytics.md`; verify every documented default matches `config/analytics.php` and the schedules.

## 3. History and period metrics

- [x] 3.1 Implement `EquityHistory` to load an owner's range chronologically, compute start/end equity, period return, peak equity, and maximum drawdown, and downsample to the configured point cap while preserving the first and last points; verify normal, single-point, empty, zero-start, and downsampling cases in `tests/Unit/EquityHistoryTest.php`.
- [x] 3.2 Document the range metrics, their formulas, and the few-points behavior in `docs/analytics.md`; verify the formulas match the tests and no fabricated values are described for empty or single-point ranges.

## 4. Dashboard equity section

- [x] 4.1 Extend `DashboardController` to accept a validated 7/30/90-day range and share `equityHistory` points, metrics, selected range, and available ranges while preserving the existing summary and activity props; verify default range, explicit ranges, invalid range fallback, ownership, and empty history in `tests/Feature/DashboardTest.php`.
- [x] 4.2 Render the equity-history section on `Dashboard.vue` using `EquityChart`, range controls, metric cards, a collecting/empty state, and an independent one-minute partial refresh; verify the Inertia props and rendered labels in `tests/Feature/DashboardTest.php` and confirm `npm run build` succeeds.
- [x] 4.3 Document the dashboard section, range behavior, refresh cadence, and empty state in `docs/dashboard.md`, linking to `docs/analytics.md`; verify the route and test-map entries match the implemented controller and tests.

## 5. Integration and verification

- [x] 5.1 Add an end-to-end test that captures an account snapshot, requests the dashboard as its owner, asserts the point and metrics appear, and confirms another user sees no history; verify in `tests/Feature/EquityAnalyticsIntegrationTest.php`.
- [x] 5.2 Mark live equity analytics shipped in `docs/next-features.md` and make the next recommendation parameter sweep or multi-exchange support; verify no remaining text describes equity history as missing.
- [x] 5.3 Run the full suite with `CACHE_STORE=array` and `QUEUE_CONNECTION=sync`; verify all tests pass.
- [x] 5.4 Run PHPStan, the Vite build, and `openspec validate live-equity-analytics --strict`; verify all three succeed.

## Workflow follow-up

- Request code review after tracked tasks are complete and fix Critical or Important findings.
- Archive the change after review, using the OpenSpec flow.
- Push the archived result.
