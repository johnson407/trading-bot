# Tasks

## 1. Exchange candles and caching

- [x] 1.1 Branch from the latest `origin/main` and confirm `spacing_hysteresis_percent` is present; verify with `git merge-base --is-ancestor origin/main HEAD` and a grep of `app/Http/Requests/Concerns/BuildsStrategyConfiguration.php`.
- [x] 1.2 Add `App\Domain\Exchange\ValueObjects\Candle` and a `candles(string $symbol, string $timeframe, int $limit): array` method to `ExchangeClient`. Implement it in `CcxtExchangeClient` with `fetch_ohlcv`, `Number::decimalString()` for every numeric, ascending order, duplicate-open-time removal, malformed-row filtering, and the existing operation/error mapping; verify with `tests/Feature/CcxtClientTest.php` cases for mapping, order, a scientific-notation close, malformed rows, and a normalized failure.
- [x] 1.3 Add `fetch_ohlcv()` with configurable rows and failure injection to `tests/Fixtures/FakeCcxtClient.php`; keep the interface contract case in `tests/Feature/ExchangeContractTest.php` and point the CCXT mapping tests at the real client with the fake.
- [x] 1.4 Delegate `candles()` from `PaperExchangeClient` to its public-data client and add a configurable candle list plus failure flag to `FakeExchangeClient`; verify the contract tests cover both implementations.
- [x] 1.5 Add bounded public-client settings to `ExchangeClientFactory::forExchange()` and an optional `timeoutMs` to `CcxtExchangeClient` used when creating the public client; verify with tests that the candle path uses one attempt and the configured timeout while normal market calls keep the defaults.
- [x] 1.6 Add the `trading.grid_activity` config block (`ranges`, `default_range`, `cache_seconds` 60, `failure_cache_seconds` 30, `request_timeout_ms` 5000, `max_attempts` 1, `max_markers` 2000, `max_grids` 200); verify the defaults through a unit assertion.
- [x] 1.7 Add `App\Services\Exchange\CandleProvider` that resolves the bounded public client through the factory, uses the exchange/symbol/timeframe/limit cache key, caches success and failure separately, single-flights with a short cache lock, and returns an unavailable result instead of throwing; verify with tests for a cache hit, cache miss, cached failure, lock-held fallback, and public/no-credential retrieval.

## 2. Grid activity data and endpoint

- [x] 2.1 Add a forward migration for the `orders (bot_id, closed_at)` index and a matching `down()`; verify it runs against the test database and the index name is present.
- [x] 2.2 Add `App\Services\GridActivity\GridActivityService` implementing half-open window boundaries, range mapping (`1h`/`6h`/`24h`/`7d`), grid overlap selection with displayed start/end and `current`/`trading` flags plus eager-loaded levels for the capped set, newest-first capped fills and cancels, and exact summary metrics as defined in `design.md` (including all-cause cancels, stored revisions, median of stopped grids, dynamic spacing from the latest grid, config-authoritative spacing for every static strategy, and dynamic-only hysteresis).
- [x] 2.3 Test `GridActivityService` with bot, grid, level, order, and fill factories: dynamic vs static spacing, a static geometric bot whose stored grid says `arithmetic` still reports the config spacing, a dynamic bot with no grid yet, a grid created before the window, stop/pause with the grid still active, restart with the same ladder, exact boundary timestamps, newest-marker truncation, no fills, no stopped grids, and a grid with no levels.
- [x] 2.4 Add `GET /bots/{bot}/grid-activity` (`bots.grid-activity`) to `routes/web.php` with `throttle:bot-preview`, and a `gridActivity` action in `BotController` that authorizes `view`, falls back to `24h` for an absent or unknown range, and renders `Bots/GridActivity` with the design's payload.
- [x] 2.5 Add `tests/Feature/GridActivityTest.php` with feature tests for the owner payload, cross-user `403`, default and unknown range, unavailable price history, empty window with candles available, truncation flags, all-cause cancel labeling, dynamic/static summary fields, and public candle retrieval without credentials.

## 3. Grid activity UI

- [x] 3.1 Add `resources/js/Support/gridActivity.js` for range labels/defaults, time formatting, magnitude-aware price formatting, and marker/table shaping; verify with `resources/js/Support/gridActivity.test.js` run by `npm run test:js`.
- [x] 3.2 Add a chart component using `vue-chartjs` with a fixed epoch-ms x domain, a y domain derived from price/markers/grid levels, a custom plugin for clipped grid-level segments, scatter datasets for fills and cancels, and a no-price-line mode when candles are unavailable; verify `npm run build` succeeds.
- [x] 3.3 Add `resources/js/Pages/Bots/GridActivity.vue` with the range selector, marker toggles, churn summary (stored revisions vs restart re-arms, all-cause cancels, median definition, dynamic-only hysteresis), truncation notices, degraded-price notice, empty state precedence, accessible data table, current-ladder-not-trading label, and a 30-second partial reload only while the tab is visible; verify the build and feature-test prop assertions.
- [x] 3.4 Add the Grid activity link to the bot detail header in `resources/js/Pages/Bots/Show.vue` and assert it in `tests/Feature/BotDetailTest.php`; verify the focused test passes.
- [x] 3.5 Document the page in `docs/dashboard.md` (Bot detail link, Grid activity section, 30-second visible-only polling, Test map row), add `bots.grid-activity` and `GridActivityTest` to the executable lists in `tests/Unit/DashboardDocsTest.php`, and document the bounded public OHLCV path plus `trading.grid_activity.*` keys in `docs/exchanges.md` and `tests/Unit/ExchangeDocsTest.php` if that test's key list is extended; verify both unit tests pass.

## 4. Integration verification

- [x] 4.1 Run the full PHP suite with the documented environment overrides and confirm it passes.
- [x] 4.2 Run PHPStan level 5 and Pint on the changed files and confirm both are clean.
- [x] 4.3 Run `npm run build` and `npm run test:js` and confirm both pass.

## Workflow follow-up

- Update issue #17 item 3 with the shipped range, summary, and docs link after merge.
- Archive the change once review and merge requirements are satisfied.
