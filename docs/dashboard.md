# Dashboard

The dashboard is an authenticated Inertia.js + Vue 3 application. All pages below
require `auth` and (except profile/auth pages) verified email. Every bot,
credential, alert, and control action is authorized against the authenticated user.

## Authentication

Breeze provides registration, login, password reset, email verification, and
profile management. Guests are redirected to `/login`; unauthenticated access to a
bot, credential, or control endpoint is rejected. `AuthorizationTest` covers guest
redirects, owner access, and cross-user denial.

## Dashboard — `GET /dashboard` (`dashboard`)

Shows bot counts by state, aggregate realized and unrealized P&L, the account
equity history, and the ten most recent activity rows for the signed-in owner.
It also shows a warning when the Go engine heartbeat is missing or expired. The
warning is informational only: it never pauses bots, cancels orders, or activates
the kill-switch, and it refreshes with the same five-second partial reload.

The equity section accepts `?range=7`, `?range=30`, or `?range=90`; an omitted or
unknown range falls back to the configured 30-day default. It renders the selected
range's account equity curve with period return, peak equity, and maximum
drawdown. A range with no snapshots shows an empty state, and a range with fewer
than two snapshots is marked as collecting without fabricated metrics. The section
refreshes independently every minute, while the summary and recent activity keep
their five-second partial reload. See [analytics.md](analytics.md) for capture
cadence, formulas, and retention.

Feature test: `tests/Feature/DashboardTest.php`.

## Bot list — `GET /bots` (`bots.index`)

Shows the signed-in user's bots with name, symbol, exchange, mode, status,
open-order count, realized P&L, unrealized P&L, and last tick. The page polls every
five seconds with `router.reload({ only: ['bots'] })`, preserving scroll and
component state. Bots belonging to other users never appear.

Feature test: `tests/Feature/BotListTest.php`.

## Create bot — `GET /bots/create` (`bots.create`) and `POST /bots` (`bots.store`)

The page has two modes. **Quick setup** asks for name, exchange, symbol, capital,
and center price, then derives the ladder from a preset. **Advanced** exposes the
full parameter set: strategy, fee rate, center price, level count, spacing
type/value, order quantity, and strategy-specific parameters (trailing distance,
martingale size multiplier/caps, dynamic volatility min/max/multiplier/interval).
Switching from quick to advanced keeps every derived value, so the bot can be
fine-tuned without retyping it.

Quick-setup presets are symbol-agnostic: percentage spacing maps to geometric
spacing, and order quantity is derived from
`capital x utilization / (levels per side x center price)`. The four presets are
`balanced`, `tight`, `wide` (simple grid) and `trailing` (trailing grid); they
stay within the default 20-order and 1000-quote risk limits at a 1000 capital
allocation. Martingale and dynamic grids remain advanced-only.

The form shows a live ladder preview before saving. It calls
`POST /bots/preview` (`bots.preview`), which validates the setup and returns
levels, per-order size, notional totals, effective risk limits, and advisories
without writing anything. `GET /bots/market-data` (`bots.market-data`) supplies
the optional current-price button; when the exchange is unreachable the form
falls back to a manually entered center price.

- Mode defaults to **paper**.
- Live mode requires a stored exchange credential and the live configuration gate.
- Invalid symbol, capital, levels, spacing, quantity, fee, strategy, missing
  strategy-specific parameters, unbuildable geometry, and orders below exchange
  minimums (when market data is available) are rejected with field errors.
- Advisory conditions never block saving: order count above the open-order cap,
  buy notional above the position cap, a center price far from the market, and
  unfunded sells. They are warnings, while risk limits stay enforced per order at
  tick time.
- Creation also creates the strategy configuration and user/bot risk limits, and
  records `bot.created`.
- Dynamic grids do not ask for spacing type or value; spacing comes from the
  volatility parameters.

Feature tests: `tests/Feature/BotCreationTest.php`, `tests/Feature/BotPreviewTest.php`,
`tests/Feature/MarketDataTest.php`.

## Bot detail — `GET /bots/{bot}` (`bots.show`)

Shows mode/status, realized and unrealized P&L, inventory, a grid ladder with
price/side/state, open orders, recent fills, and risk limits. The mark price for
unrealized P&L is the bot's stored mark (latest tick), otherwise the average entry
price — the same source the bot list and dashboard use. The page polls every five seconds for
`bot, grid, openOrders, recentFills, inventory, pnl`; a new fill appears on the
next poll.

Open orders have a **Cancel** action (`bots.orders.cancel`). It publishes
`order.cancel` to the engine and leaves the order row unchanged until the engine
confirms the cancellation.

Feature test: `tests/Feature/BotDetailTest.php`.

## Protective exits — `PATCH /bots/{bot}/exit-guard` (`bots.exit-guard.update`)

The bot detail page shows a **Protective exits** card for every bot state. The
owner can set an optional stop-loss percent, take-profit percent, range low, and
range high. Leaving a field blank disables that rule.

- Stop-loss and take-profit are percentages from the bot's average entry price;
  they require positive base inventory and an average entry.
- Range low/high are absolute mark-price bounds and work with or without
  inventory. When both are set, high must be greater than low.
- Stop-loss and take-profit must be greater than 0 and less than 100. Range
  values must be greater than 0. Percentages allow up to 4 decimal places and
  ranges up to 8; larger values are rejected rather than silently rounded.
- Saving flashes `Protective exits updated.` and records
  `bot.exit_guard_updated` with the before/after values.
- A trigger pauses the bot and cancels open orders; it never sells inventory and
  does not resume automatically. See [risk.md](risk.md) and
  [bot-parameters.md](bot-parameters.md).

Feature test: `tests/Feature/BotExitGuardTest.php`.

## Controls — `POST /bots/{bot}/start|pause|stop`

- **Start** (`bots.start`) moves idle/paused/stopped/error bots to running and
  clears the last error.
- **Pause** (`bots.pause`) pauses a running bot while preserving open orders.
- **Stop** (`bots.stop`) pauses/cancels every open order and marks the bot stopped.

For live bots, start requires a confirmation string equal to `<bot name> on <exchange>`,
the `TRADING_LIVE_ENABLED` gate, and enough free quote balance. Creating a live bot
requires the same phrase; the error is reported on `live_confirmation`. Missing or mismatched
confirmation is returned as a `confirmation` error; other failures are returned as
an `action` error and leave the prior state unchanged. Cross-user control is
denied.

Feature test: `tests/Feature/BotControlTest.php`.

## Edit — `GET /bots/{bot}/edit` and `PATCH /bots/{bot}`

The edit form renders the same ladder preview as creation, evaluated from the
bot's current risk limits. The preview refreshes as editable fields change. Only the owner may edit, and
only while the bot is idle or stopped. Editable
fields are name, capital allocation, fee rate, and strategy parameters. Symbol,
exchange, mode, and strategy are immutable; submitted values for them are ignored.
Updates preserve the existing grid, strategy state, and inventory when the
structural strategy parameters (center, levels, spacing, quantity, or
strategy-specific limits) are unchanged. Changing them retires the active grid,
cancels any remaining orders, and clears the strategy state so the next tick
reinitializes from the new configuration. The edit page itself is viewable for any
owned bot; bots that are not idle or stopped show a read-only explanation and PATCH
is denied. Failures are returned as an authorization error or validation errors on
the edit page.

Feature test: `tests/Feature/BotUpdateTest.php`.

## Activity — `GET /bots/{bot}/activity` (`bots.activity`)

Shows the latest 100 activity entries and 100 risk events for one bot, newest
first, including severity, type, message, timestamp, and JSON context. Access is
limited to the bot owner.

Feature test: `tests/Feature/ActivityLogTest.php`.

## Risk limits — `GET /risk-limits` (`risk-limits.edit`) and `PATCH /risk-limits` (`risk-limits.update`)

Edits the account-wide maximum position notional, maximum open orders, and daily loss limit. These apply to every bot without a bot-scope override. A successful save flashes `Risk limits updated.` and validation errors appear under each field.

## Kill switch — `GET /kill-switch` (`kill-switch.create`) and `POST /kill-switch` (`kill-switch.store`)

The confirmation page shows how many bots and open orders will be affected.
Submitting the exact phrase `KILL SWITCH` pauses every non-stopped bot for the
user and cancels all open orders in parallel. Per-bot cancellation failures are
reported; the bots are paused immediately regardless.

Feature test: `tests/Feature/KillSwitchUiTest.php`.

## Exchange credentials — `/exchange-credentials` resource

Routes: `exchange-credentials.index`, `exchange-credentials.create`,
`exchange-credentials.store`, `exchange-credentials.edit`,
`exchange-credentials.update`, and `exchange-credentials.destroy`.

`index`, `create`, `store`, `edit`, `update`, and `destroy` are available; `show`
is intentionally omitted.

- API keys, secrets, and passphrases are encrypted at rest and never rendered.
- List and edit responses expose only a masked key such as `abcd****5678`.
- Saving the same exchange replaces the existing row instead of duplicating it.
- Another user cannot view, update, or delete a credential.
- The form defaults to testnet and offers Binance only.

Feature test: `tests/Feature/ExchangeCredentialUiTest.php`.

## Alerts — `GET /alerts` (`alerts.index`)

Shows the signed-in user's operational alerts newest first, 50 per page. Each row
includes severity, title, message, bot link when present, timestamp, and read
state. An optional bot filter narrows the list to one owned bot; another user's bot
id is rejected with 404.

- `POST /alerts/{alert}/read` marks one owned alert read.
- `POST /alerts/read-all` marks all visible unread alerts read and honors the active
  bot filter.
- The shared `auth.unread_alerts` count drives the badge in both navigations.
- Critical incidents are also emailed after commit; warnings stay in-app. See
  [alerts.md](alerts.md) for the covered incidents and delivery details.

Feature tests: `tests/Feature/AlertInboxTest.php`,
`tests/Feature/AlertIntegrationTest.php`.

## Backtest parameter sweeps — `/backtests/sweeps`

Routes: `backtest-sweeps.index`, `backtest-sweeps.create`,
`backtest-sweeps.store`, and `backtest-sweeps.show`.

- **List** shows the signed-in owner's latest 100 sweeps with bot, symbol,
  timeframe, period, combination count, progress bar, derived status, and
  completed/failed/active counts. It polls every three seconds only while a sweep
  has active runs.
- **Create** offers only the owner's configured grid bots, the configured
  timeframes, date presets, comma-separated center/spacing/level inputs, a live
  combination count, and a warning when the count exceeds the configured cap. It
  posts to `backtest-sweeps.store`.
- **Detail** shows the swept value lists, derived status and progress, and one row
  per run with its center, spacing, levels, status, return, maximum drawdown, and
  end reason. Each row links to the normal backtest detail page. While any run is
  pending or running, the page polls the sweep, runs, compare URL, and active flag.
  When two to four runs have completed, it shows **Compare completed**, which opens
  the existing `backtests.compare` page.
- All four routes are inside the authenticated, verified group and are
  owner-scoped. Another owner's sweep returns 404. Sweep runs are ordinary
  backtests, so another owner's run is denied with 403 by the normal backtest
  authorization.
- A rejected create request returns field errors and creates no sweep or run. Only
  center price, spacing value, level count, and `levels_per_side` differ per run;
  everything else comes from the bot snapshot.

Feature test: `tests/Feature/BacktestSweepDashboardTest.php`.

## Polling and freshness

List and detail pages poll every five seconds, meeting the MVP's five-second
status target without WebSockets. Polls request only the changing props and
preserve scroll/state. Control actions use normal Inertia POSTs with CSRF
protection; a failed action never leaves a partial state change visible.

## Test map

| Flow | Route | Test |
| --- | --- | --- |
| Dashboard | `dashboard` | `DashboardTest` |
| Bot list | `bots.index` | `BotListTest` |
| Create bot | `bots.create`, `bots.store` | `BotCreationTest` |
| Bot detail | `bots.show` | `BotDetailTest` |
| Protective exits | `bots.exit-guard.update` | `BotExitGuardTest` |
| Start/pause/stop | `bots.start`, `bots.pause`, `bots.stop` | `BotControlTest` |
| Activity | `bots.activity` | `ActivityLogTest` |
| Kill switch | `kill-switch.create`, `kill-switch.store` | `KillSwitchUiTest` |
| Credentials | `exchange-credentials.*` | `ExchangeCredentialUiTest` |
| Alerts | `alerts.index`, `alerts.read`, `alerts.read-all` | `AlertInboxTest` |
| Backtest sweeps | `backtest-sweeps.index`, `backtest-sweeps.create`, `backtest-sweeps.store`, `backtest-sweeps.show` | `BacktestSweepDashboardTest` |
| Authorization | all bot/credential routes | `AuthorizationTest` |
