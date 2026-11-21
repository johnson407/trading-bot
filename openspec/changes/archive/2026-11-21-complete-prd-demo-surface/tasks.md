# Tasks

## 1. Exchange Selection

- [x] 1.1 Add the `trading.exchanges` allowlist from `TRADING_EXCHANGES` (default `binance`), document it in `README.md` and `docs/exchanges.md`, and verify `php artisan config:show trading` reports the parsed list.
- [x] 1.2 Write a failing feature test that creates a bot with an exchange outside the allowlist and asserts an `exchange` validation error; then validate `StoreBotRequest.exchange` against `Rule::in(config('trading.exchanges'))` and pass that list from `BotController@create` to `Bots/Create.vue`.
- [x] 1.3 Write a failing test binding two fake exchanges and asserting each bot reads market data from its own exchange; then add the optional `exchange` constructor argument to `CcxtExchangeClient` and `PaperExchangeClient`, and build the client per bot in `ExchangeClientFactory::forBot()` while preserving the existing test constructor overrides.
- [x] 1.4 Write a failing test that a live bot on a non-default allowlisted exchange sends order and cancel calls through the client bound to that exchange; then resolve the client from the factory in every order path and verify `bots.exchange` is the value used.
- [x] 1.5 Verify the exchange suite and the existing `CcxtClientTest`, `PaperExchangeClientTest`, and `ExchangeContractTest` pass with a fake `kraken` exchange in the allowlist.

## 2. Dashboard

- [x] 2.1 Write a failing feature test asserting `flash.status` is shared as an Inertia prop after a bot start; then add it to `HandleInertiaRequests::share()`.
- [x] 2.2 Write failing page tests for navigation and flash rendering; then add `FlashMessage.vue`, mount it in `AuthenticatedLayout.vue`, and add Bots, Exchange credentials, and Kill switch links to the desktop and responsive menus. Verify the links resolve to the named routes.
- [x] 2.3 Write a failing test asserting the dashboard exposes bot counts, aggregate P&L, and recent activity; then replace the Breeze placeholder `Dashboard.vue` with the summary page and its five-second polling.
- [x] 2.4 Write failing tests for the bot list columns and detail-page activity link; then add `exchange` and `unrealized_pnl` to the bot summary resource, compute unrealized P&L with the ledger and each bot's stored mark in `BotController@index`, extend `Bots/Index.vue`, and link `bots.activity` from `Bots/Show.vue`.
- [x] 2.5 Write a failing test that a rejected control action surfaces its error; then render `errors.confirmation` and `errors.action` on `Bots/Show.vue` and verify start, pause, and stop all show feedback.

## 3. Live Confirmation

- [x] 3.1 Write failing tests for `LiveStartConfirmation::matches()` covering the correct `<bot name> on <exchange>` phrase, a name-only phrase, a wrong-exchange phrase, and surrounding whitespace; then implement the comparison and update existing tests that used the name alone.
- [x] 3.2 Write a failing feature test that creating a live bot without a matching confirmation is rejected and creates nothing; then enforce the phrase in `StoreBotRequest` when `mode` is live, with the error on `live_confirmation`.
- [x] 3.3 Write a failing feature test that starting a live bot with a name-only confirmation is rejected with `errors.confirmation`; then confirm `LiveModeGuard`/`StartBot` reject the phrase and the controller maps the exception.
- [x] 3.4 Add the live confirmation field and exact-phrase helper text to `Bots/Create.vue` for live mode and to the start control on `Bots/Show.vue`; verify both errors render.

## 4. Bot Editing

- [x] 4.1 Write failing authorization tests: the owner may update an idle or stopped bot; the owner may not update a running or paused bot; another user may not update any bot. Then add `BotPolicy@update` and register the `PATCH bots/{bot}` route behind `auth` and `throttle:bot-control`.
- [x] 4.2 Write failing request tests that a valid update changes only name, capital allocation, fee rate, and strategy parameters, and that submitted symbol, exchange, mode, or strategy key are ignored or rejected. Then add `UpdateBotRequest` and `BotController@update`, updating the bot and its `StrategyConfig` in one transaction and recording `bot.updated`.
- [x] 4.3 Write a failing page test for the edit screen; then add `GET bots/{bot}/edit`, `Bots/Edit.vue`, and an edit link on the detail page. Show identity fields read-only and replace the form with an explanation when the bot is not idle or stopped.
- [x] 4.4 Verify the update path preserves the existing grid and strategy state and does not change the stored exchange, symbol, or mode.

## 5. Verification

- [x] 5.1 Run the focused exchange, dashboard, confirmation, and edit suites in Docker with the testing environment overrides and confirm all pass.
- [x] 5.2 Run the full Docker suite and confirm no regressions against the 272-pass, 1-skip baseline.
- [x] 5.3 Run PHPStan level 5 with Larastan and confirm no errors.
- [x] 5.4 Run the domain coverage configuration and confirm the percentage does not fall below the current 91.8%.
- [x] 5.5 Rebuild the frontend assets and walk the dashboard in the browser: navigate to every section, create a paper bot on a non-default allowlisted exchange, trigger a success flash and a confirmation error, and edit an idle bot.
- [x] 5.6 Update `README.md` and the relevant `docs/` pages for the allowlist, confirmation phrase, and edit rules, and validate the change with `openspec validate complete-prd-demo-surface --strict`.
