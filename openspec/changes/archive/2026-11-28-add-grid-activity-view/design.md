# Design

## Context

See `proposal.md` for motivation and the `trading-dashboard` delta for required behavior.

The data needed for the view is mostly already persisted:

- `grids` keeps every stored ladder revision, with `created_at` and, once replaced, `updated_at` as its replacement time. `grid_levels` keeps each revision's levels.
- `orders` keeps `price`, `side`, `opened_at`, `closed_at`, and terminal status. A cancel is `status = canceled` with `closed_at` set.
- `fills` keeps `price`, `side`, `quantity`, and `filled_at`, already indexed by `(bot_id, filled_at)`.

Three gaps shape the approach:

1. **No historical price series.** `market_candles` is populated only by Go backtests, and `bots.settings` keeps only the latest mark and untimed recent prices. The page must fetch public OHLCV on demand.
2. **No stored cancel reason.** Re-arm cancels and grid-replacement bulk cancels never write `activity_logs`, so the view can show when and where cancels happened but cannot label them. The summary therefore counts all canceled orders and says so.
3. **`grids.updated_at` is a replacement time, not a trading-stop time.** The engine stops a grid only when it replaces the ladder. Pausing, stopping, or killing a bot cancels orders but leaves the grid `active`, and a restart can reuse it when the levels still match. The view must not draw that grid as if it were trading through the whole window.

The exchange boundary is `App\Domain\Exchange\Contracts\ExchangeClient`, implemented by `CcxtExchangeClient`, `PaperExchangeClient`, and `Tests\Fixtures\FakeExchangeClient`. `CcxtExchangeClient::market()` already demonstrates the CCXT retry/rate-limit/error-normalization pattern and `Number::decimalString()` already normalizes exchange floats.

Implementation must branch from the latest `origin/main`: `spacing_hysteresis_percent` was added by the merged hysteresis change and is absent from some local checkouts. For dynamic grids, `strategy_configs.configuration.spacing_value` is deliberately stored as `0`; the real spacing lives on the grid row.

## Goals / Non-Goals

**Goals:**

- Render grids, fills, and cancels on a real time axis for a bounded window.
- Keep the chart useful when the exchange cannot supply candles.
- Keep request cost bounded and predictable for high-churn bots and 7-day windows.
- Make every churn number's definition explicit and testable.

**Non-Goals:**

- No cancel-reason classification, no `orders.cancel_reason` column, no engine changes.
- No candle persistence or backfill; the cache is ephemeral.
- No editing of spacing or hysteresis from this page.
- No WebSocket or live-streaming feed.

## Decisions

### Decision: Dedicated read-only page

Add `GET /bots/{bot}/grid-activity` (`bots.grid-activity`), throttled with the existing `bot-preview` limiter, and a `GridActivity` Inertia page linked from the bot detail header beside Activity log. A dedicated page keeps the bot detail's 5-second poll small and lets the chart use its own slower refresh. Embedding the chart in `Show.vue` was rejected: the detail payload would grow on every poll and the chart would re-render every 5 seconds for data that changes far less often.

### Decision: Candle retrieval through the existing exchange boundary

Add to `ExchangeClient`:

```php
/** @return list<Candle> */
public function candles(string $symbol, string $timeframe, int $limit): array;
```

- `App\Domain\Exchange\ValueObjects\Candle` holds `openTime: DateTimeImmutable` and `close: Decimal`.
- `CcxtExchangeClient::candles()` calls `load_markets()` (mirroring `market()`), then `fetch_ohlcv($symbol, $timeframe, null, $limit)` inside `withRetry` for operation `candles`. It maps each row's close through `Number::decimalString()` and `Decimal::of()`, drops rows with missing/non-numeric open time or close, de-duplicates identical open times, and sorts ascending.
- `PaperExchangeClient` delegates `candles()` to its public-data client, matching `market()`.
- `FakeExchangeClient` gains a configurable candle list and failure flag. `FakeCcxtClient` gains `fetch_ohlcv()` with configurable rows and failure injection so the real mapping code is tested, not only the fake.
- `ExchangeClientFactory::forExchange()` gains optional bounded public-client settings (`maxAttempts`, `timeoutMs`) and `CcxtExchangeClient` gains an optional `timeoutMs` override used when it creates its public client. The factory caches bounded and default clients under different keys so a bounded candle client cannot leak into previews. The candle provider uses one attempt and `trading.grid_activity.request_timeout_ms` (default 5000), so a slow exchange cannot hold the page for the normal three-attempt, 15-second path.

A separate `CandleProvider` that builds its own CCXT client was rejected: it would duplicate retry, rate-limit, allowlist, and error-normalization behavior outside the established boundary. A nullable optional method was rejected because every implementation must be explicit.

### Decision: Short cache with a cached failure state and single flight

`App\Services\Exchange\CandleProvider` resolves the bounded public client and caches a wrapper keyed by the **bot's** exchange, symbol, timeframe, and limit (not the client's `exchange()`, which is `paper` for paper mode):

```php
['available' => bool, 'points' => [['t' => epochMs, 'price' => '...'], ...], 'error' => ?string]
```

- Successful results cache for `trading.grid_activity.cache_seconds` (default 60).
- Failures cache for `trading.grid_activity.failure_cache_seconds` (default 30) so a dead exchange is not retried on every poll.
- A short `Cache::lock` (`TTL` 10 seconds, block at most 1 second) makes concurrent cache misses single-flight. If the lock cannot be acquired, the request returns an unavailable price result rather than waiting indefinitely.
- `Throwable` is logged without secrets and returned as `available = false`, matching `MarketDataResolver`'s optional-data stance.
- Worst-case page delay on a cold miss is about 11 seconds: market loading and the candle request can each consume the 5-second HTTP timeout, plus the 1-second lock wait. There is no separate price endpoint; candles are fetched during the initial Inertia response, so the initial page request blocks for that duration before rendering. Subsequent 30-second partial reloads keep the existing page, and later requests within the failure cache return immediately.

Persisting candles into `market_candles` was rejected for this change: it adds write-path and retention concerns without improving a 7-day window. It remains a possible later optimization.

### Decision: Fixed range-to-timeframe mapping

Ranges are defined server-side and documented:

| Range | Timeframe | Candle limit |
| --- | --- | --- |
| `1h` | `1m` | 60 |
| `6h` | `5m` | 72 |
| `24h` | `5m` | 288 (default) |
| `7d` | `1h` | 168 |

The query parameter is `?range=`. An absent or unknown value falls back to `24h` rather than returning a validation error, so a stale bookmark still renders. All timeframes are already supported by the CCXT and backtest paths.

### Decision: Grid overlap, displayed lifetimes, and revisions

A stored grid is shown when `created_at < window_end` and (`status = active` or `updated_at > window_start`). Window boundaries are half-open `[window_start, window_end)`.

Each grid's displayed lifetime is:

- start: `max(created_at, window_start)`;
- end for a stopped grid: `min(updated_at, window_end)`;
- end for the current grid while the bot is running: `window_end`;
- end for the current grid while the bot is paused, stopped, or in error: `min(window_end, last_successful_tick_at ?? last_tick_at ?? created_at)`, clamped so it is not before the start.

The current grid is flagged `current = true`, and `trading = true` only while the bot's status is `running`. A non-running bot's current grid is labeled "current ladder, not necessarily trading" rather than drawn as live. This uses existing bot columns; no engine change is needed.

A **stored grid revision** is one row in `grids` that overlaps the window. A stop/start that reuses the same ladder does not create a row and therefore does not count; the UI states this.

Grids are ordered newest first, capped at `trading.grid_activity.max_grids` (default 200, with one extra row used only to set the truncation flag), then reversed for rendering. Levels are eager-loaded only for that capped set. The summary's revision count comes from a separate exact count query.

### Decision: Newest-first marker caps

Fills and cancels are queried independently:

- fills: `bot_id`, `filled_at >= window_start` and `< window_end`, ordered `filled_at DESC, id DESC`, limit `max_markers + 1`;
- cancels: `bot_id`, `status = canceled`, `closed_at >= window_start` and `< window_end`, ordered `closed_at DESC, id DESC`, limit `max_markers + 1`.

The extra row only sets the truncation flag. The newest `max_markers` rows are kept and then reversed for chronological rendering, so a high-churn bot shows the most recent activity rather than the oldest. Exact counts still come from aggregate queries, and the notice reads "showing the most recent N of M".

A migration adds `orders (bot_id, closed_at)`; without it the cancel query scans the bot's order history. The existing `(bot_id, filled_at)` index covers fills.

### Decision: Exact, defined summary metrics

The churn summary is computed from aggregate queries over the whole window, not from the capped marker lists:

- **Fill count** and **all-cause cancel count**: exact counts. The UI labels cancels as including grid replacement, re-arm, pause, stop, operator, and kill-switch cancels.
- **Cancel-to-fill ratio**: `cancel_count / fill_count` computed with the decimal helper and rounded to two decimals as a numeric string, or `null` when `fill_count` is zero (displayed as "no fills"). No binary float is used.
- **Grid revisions**: exact count of stored grid rows overlapping the window, as defined above.
- **Median lifetime**: median of `updated_at - created_at` for stopped grids whose `updated_at` falls inside the window, computed with PostgreSQL `percentile_cont(0.5)` so no row cap is needed. Lifetimes are full lifetimes, not clipped to the window. The current grid is excluded; the value is `null` when no grid was replaced in the window. Seconds are rounded to an integer.
- **Current spacing**: for a `dynamic-grid` bot, the most recent grid row's `spacing_value` (active grid first, otherwise latest by `created_at`), with `spacing_type = dynamic`; a dynamic bot with no grid shows `null` because its spacing has not been computed yet. For every static strategy, read `spacing_type` and `spacing_value` from `strategy_configs.configuration` regardless of whether a grid exists: the engine stores the first-level absolute gap and rewrites a static geometric ladder's spacing type as `arithmetic`, so the grid row is not authoritative for static bots.
- **Spacing hysteresis**: `strategy_configs.configuration.spacing_hysteresis_percent` for dynamic grids, defaulting to `20` when absent; `null` for every other strategy.

The chart may therefore say "showing the most recent 2,000 of 4,121 cancels" while the summary still reports 4,121.

### Decision: Client-side chart without new dependencies

`package.json` has `chart.js` and `vue-chartjs` but no date adapter and no annotation plugin, so:

- the x-axis is `linear` with epoch-millisecond values and a fixed domain `[window_start, window_end]`; ticks/tooltips format time manually;
- the y-axis domain is computed from price points, fill/cancel prices, and all displayed grid levels, so grid lines never fall outside the plot;
- grid levels are horizontal segments drawn by a small custom Chart.js plugin using the chart's pixel scales and the grid's displayed time bounds — no annotation plugin;
- fills and cancels are scatter datasets with distinct colors and point styles;
- if price history is unavailable but grids or markers exist, the chart renders without the price line using the remaining data; if no grids, fills, or cancels exist, the page shows the empty state and no chart, even when candles exist;
- the page includes a `<details>` table of price points, fills, and cancels, following `EquityChart.vue`'s accessible-data pattern.

Range changes use `router.get(..., { only: ['gridActivity'], preserveState: true, preserveScroll: true, replace: true })`. The page polls `gridActivity` every 30 seconds only while `document.visibilityState === 'visible'`.

A shared helper in `resources/js/Support/gridActivity.js` owns range labels/defaults, time formatting, magnitude-aware price formatting for the axis, and marker/table shaping so it can be tested with `node --test` like the other `Support` helpers. Summary money values still use `formatMoney`; price-axis labels use the precision-aware helper because 2 decimals are inadequate for low-priced symbols.

### Decision: Payload shape

Top-level Inertia props remain camelCase like `equityHistory`; nested keys are snake_case like the rest of the API.

```php
'bot' => BotSummaryResource::make($bot)->resolve(),
'gridActivity' => [
    'range' => '24h',
    'available_ranges' => ['1h', '6h', '24h', '7d'],
    'window_start' => ISO8601,
    'window_end' => ISO8601,
    'price' => [
        'available' => true,
        'timeframe' => '5m',
        'points' => [['t' => 1730000000000, 'price' => '100.5'], ...],
        'error' => null,
    ],
    'grids' => [[
        'id', 'center_price', 'level_count',
        'current', 'trading', 'started_at', 'ended_at',
        'levels' => [['sequence', 'price', 'side', 'state'], ...],
    ], ...],
    'fills' => [['id', 'side', 'price', 'quantity', 'filled_at'], ...],
    'cancels' => [['id', 'side', 'price', 'closed_at'], ...],
    'summary' => [
        'fill_count', 'cancel_count', 'cancel_fill_ratio', 'grid_revisions',
        'median_lifetime_seconds', 'spacing', 'spacing_type',
        'spacing_hysteresis_percent', 'cancel_causes' => 'all',
    ],
    'truncated' => ['fills' => false, 'cancels' => true, 'grids' => false],
],
```

Decimal values remain strings, consistent with the rest of the API. `cancel_fill_ratio` is a string or null; `median_lifetime_seconds` is an integer or null; `spacing` is a string or null. Per-grid spacing fields are omitted because the stored `spacing_type` is unreliable for static geometric strategies; the summary reports the config-authoritative static spacing and the dynamic ladder spacing.

## Metric definitions

| Metric | Definition |
| --- | --- |
| Fill count | Fills with `filled_at` in `[window_start, window_end)` |
| Cancel count | Orders with `status = canceled` and `closed_at` in the window; all causes |
| Cancel-to-fill ratio | `cancel_count / fill_count`, two decimals, null when no fills |
| Grid revision | One stored `grids` row overlapping the window; restart re-arms do not add one |
| Median lifetime | Median `updated_at - created_at` of grids replaced inside the window; current grid excluded |
| Current spacing | Static strategies: config `spacing_type` and `spacing_value`; dynamic: most recent grid's `spacing_value`; null when a dynamic bot has no grid |
| Spacing hysteresis | Dynamic grids only; config value, default 20; null for other strategies |

## Risks / Trade-offs

- **Exchange outage or rate limiting degrades the chart.** → One bounded attempt (5 seconds for market loading and 5 seconds for candles), a 1-second lock wait, a cached failure for 30 seconds, and the `bot-preview` route throttle prevent a retry stampede; the first cold miss can take up to about 11 seconds and later requests fail fast.
- **Candle cache and polling can make data up to about 90 seconds old.** → 60-second success cache plus 30-second poll; acceptable for a churn view and documented. No money or order decisions read this data.
- **Cancel causes remain ambiguous.** → The count is explicitly labeled all-cause and the UI does not claim a churn-only reason. Grid replacement seams plus counts remain the tuning signal; a reason column is a separate change.
- **`grids.updated_at` is a replacement time, not a trading-stop time.** → Displayed lifetimes cap a non-running bot's current grid at its last successful tick and label it as not necessarily trading; median lifetime excludes the current grid. Tests pin stop, pause, and restart behavior.
- **7-day windows can return large result sets.** → Newest-first server caps, aggregate counts, the cancel index, and the `level_count` maximum of 50 bound the payload.
- **The new index takes a brief write lock while it builds.** → The table is small for this deployment; the migration is additive and the index is harmless if the feature is rolled back.

## Migration Plan

- Add one forward migration creating `orders_bot_id_closed_at_index` on `(bot_id, closed_at)`. It is additive and safe to leave in place if the feature is reverted.
- Add the `trading.grid_activity` config block: `ranges`, `default_range`, `cache_seconds` 60, `failure_cache_seconds` 30, `request_timeout_ms` 5000, `max_attempts` 1, `max_markers` 2000, `max_grids` 200.
- Update `docs/dashboard.md` (Bot detail link, Grid activity section, Polling, Test map), `tests/Unit/DashboardDocsTest.php` route/test lists, and `docs/exchanges.md` for the bounded public OHLCV path and config keys.
- Deploy code and migration together; no backfill and no data rewrite.
- Rollback is code-only: remove the route/page; the index is harmless. No engine or queue changes.

## Open Questions

None.
