# Tasks

## 1. Latest equity snapshot

- [x] 1.1 Add `latest` (`{at, equity}|null`) to `EquityHistory::forUser()` using the user's newest snapshot independent of the selected range, and update the return-shape PHPDoc; verify `EquityHistoryTest` covers the new key.
- [x] 1.2 Add tests proving the latest snapshot survives a range with no points, is `null` with no snapshots, and remains scoped to the owning user; verify `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync app php artisan test --filter=EquityHistoryTest` passes.
- [x] 1.3 Run `docker compose exec -u www-data app vendor/bin/pint --dirty` and `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`; verify both pass.

## 2. EquityChart height control

- [x] 2.1 Add a `heightClass` string prop (default `h-80`) to `EquityChart.vue` and apply it to the chart container; verify the existing Backtests chart usages still build with `docker compose exec -u www-data app npm run build`.

## 3. Dashboard composition

- [x] 3.1 Replace the dashboard `#header` band and `py-12` wrapper with a slim in-content title row containing Create bot and the danger-styled Kill switch, and remove the footer links; verify the page renders without a duplicate header band at desktop and mobile widths.
- [x] 3.2 Build the equity hero: latest-snapshot headline with timestamp and `—` fallback, Realized/Unrealized P&L, chart using the responsive height classes, and a flat Period return / Peak equity / Maximum drawdown strip; verify `DashboardTest` literals and `npm run build` still pass.
- [x] 3.3 Build the operations rail: always-present attention card with count badge, bounded `max-h-72` keyboard-scrollable list, "All bots healthy" empty state, compact rows, and the five-count fleet strip with a no-bots state; verify the rail keeps the fleet visible with many attention rows.
- [x] 3.4 Compact the activity table to the existing ten rows, hide the Type column below `sm`, keep archived names plain, and retain horizontal-scroll fallback; verify the table remains readable at a 375px viewport.
- [x] 3.5 Apply the density and accessibility pass: 12px meta floor, 14px body, `tabular-nums`, hairline dividers, focus-visible rings, labeled range group, scoped table headers, and text/sign cues alongside color; verify keyboard tabbing and both viewport widths.

## 4. Dashboard tests and docs

- [x] 4.1 Extend `DashboardTest` file assertions for the new composition markers (`All bots healthy`, `equityHistory.latest`, responsive chart height) while keeping every existing literal assertion; verify the focused Dashboard test passes.
- [x] 4.2 Update `docs/dashboard.md` to describe the latest-snapshot headline and the "All bots healthy" attention state instead of a hidden panel; verify `DashboardDocsTest` passes.
- [x] 4.3 Run the focused suites (`DashboardTest`, `EquityHistoryTest`, `BotHealthTest`, `BotActionsTest`, `DashboardDocsTest`) and `docker compose exec -u www-data app npm run test:js`; verify all pass.

## 5. Integration verification

- [x] 5.1 Run the full PHP suite with the documented environment overrides and verify it passes.
- [x] 5.2 Run `openspec validate redesign-dashboard-layout --strict` and verify the change validates.

## Workflow follow-up

- Archive the change after review and verification are complete.
- Verify the archived specs include the new dashboard composition requirement.
