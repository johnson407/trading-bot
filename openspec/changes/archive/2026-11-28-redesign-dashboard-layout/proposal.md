# Proposal

## Why

The dashboard has a flat hierarchy: seven equally sized stat cards, a full-width attention panel, and the equity section buried below them. Account value is not prominent, operational alerts compete with fleet counts, and generous padding makes a data-dense page scroll while individual figures still feel small. Users cannot tell at a glance whether money or bot health needs attention first.

## What Changes

- Replace the dashboard page band, seven stat cards, and footer links with a compact page header and a two-band composition:
  - **Portfolio hero** (8/12 on desktop): latest account equity headline, realized and unrealized P&L, equity chart, and a flat period-return / peak-equity / maximum-drawdown strip.
  - **Operations rail** (4/12 on desktop): needs-attention triage with bounded scrolling and a compact five-count fleet strip.
  - **Recent activity**: full width, ten compact rows, with the type column hidden on narrow screens.
- Make the equity headline use the latest snapshot independent of the selected 7/30/90-day range, so switching ranges changes the chart and metrics but not account equity.
- Show a one-line "All bots healthy" state in the attention rail instead of hiding the panel when no bot needs attention, keeping the rail composition stable.
- Add a configurable chart height so the equity chart fits the hero rather than using one fixed height.
- Preserve existing dashboard behavior: five-second polling, one-minute equity refresh, range partial reloads, resume flows, engine/error banners, archived-bot handling, and live confirmation routing.
- Update the dashboard documentation to match the composition and attention empty state.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `trading-dashboard`: adds a dashboard composition requirement covering the responsive equity-hero / operations-rail hierarchy, the latest-snapshot equity headline, the bounded attention rail with a healthy empty state, and the compact activity table. Existing overview, bot-health, and equity-history requirements remain behaviorally unchanged.

## Impact

- `resources/js/Pages/Dashboard.vue` — page structure, spacing, and section layout.
- `resources/js/Components/EquityChart.vue` — configurable chart height.
- `app/Domain/Analytics/EquityHistory.php` — expose the latest snapshot independent of the selected range.
- `tests/Feature/DashboardTest.php`, `tests/Unit/EquityHistoryTest.php`, and related dashboard UI assertions.
- `docs/dashboard.md` — composition and attention empty-state description.
- No route, migration, configuration, or dependency changes.
