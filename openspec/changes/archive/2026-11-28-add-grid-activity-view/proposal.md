# Proposal

## Why

Grid churn is currently invisible. The bot detail page shows a single ladder snapshot and the latest fills, but not how often the ladder was canceled and rebuilt, or where price sat when that happened. The churn investigation behind #16 measured 1,009 canceled orders against 38 fills, and the spacing-hysteresis fix (#23) landed without any view that lets an operator confirm the improvement or tune the threshold. A time-based view makes the cause-and-effect visible: price moves, the ladder is replaced, and the cancel:fill ratio changes.

## What Changes

- Add an authenticated per-bot **grid activity** page at `GET /bots/{bot}/grid-activity`, linked from the bot detail header beside the activity log.
- Show a price-history line for a selectable window (1h, 6h, 24h, 7d; default 24h) built from public exchange OHLCV candles fetched on demand with a bounded request and brief cache.
- Draw each stored grid's levels as horizontal segments clipped to that grid's displayed lifetime, emphasizing the current grid, so ladder replacements are visible as vertical seams.
- Plot fills and cancels in the window as distinct markers with visibility toggles, plus an accessible table fallback.
- Show a churn summary: fill count, all-cause cancel count (explicitly labeled), cancel:fill ratio, stored grid revision count, median lifetime of grids replaced in the window, and the bot's current spacing plus `spacing_hysteresis_percent` for dynamic grids.
- Degrade gracefully: if candle retrieval fails, still render grids, markers, and counts with an explicit "price history unavailable" state. Show an empty state only when the window has no grids, fills, or cancels, even if candles exist.
- Keep the most recent markers and grids when caps apply, with a "showing the most recent N of M" truncation notice. Add an `orders (bot_id, closed_at)` index to keep the cancel-window query bounded.
- Document the page in `docs/dashboard.md` and update its executable route/test lists, the polling section, and the public candle path in `docs/exchanges.md`.
- Cover it with feature tests, CCXT/fake exchange tests, and JS helper tests.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `trading-dashboard`: add a grid activity view requirement covering the range selector, price history, grid lifetimes, fill/cancel markers, churn summary, degraded price state, freshness, empty state, and output caps.

## Impact

- `routes/web.php` and `app/Http/Controllers/BotController.php`: new read-only, ownership-authorized, throttled action.
- `resources/js/Pages/Bots/`: new page, plus a chart component and a range/chart-shaping helper under `resources/js/Support/`.
- `app/Domain/Exchange/` and `app/Services/Exchange/`: public OHLCV retrieval through the existing CCXT adapter, a bounded public client, and a caching candle provider.
- New migration: index on `orders (bot_id, closed_at)`.
- `config/trading.php`: `trading.grid_activity` block.
- `docs/dashboard.md`, `docs/exchanges.md`, `tests/Unit/DashboardDocsTest.php`, and `tests/Unit/ExchangeDocsTest.php` as needed.
- Tests: feature coverage for ownership, ranges, fallback, empty state, counts, dynamic/static spacing, boundary timestamps, and newest-row caps; exchange/client coverage for OHLCV mapping and failures; JS unit coverage for range labels, time/price formatting, and chart shaping.
- No engine, strategy, risk, money-handling, or live-order changes. No cancel-reason persistence and no parameter editing from this page.
