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
Counts cover active (non-archived) bots, while aggregate realized and unrealized
P&L include archived bots so their residual balances remain visible. Recent
activity for an archived bot shows its name as plain text rather than a link.
It also shows a warning when the Go engine heartbeat is missing or expired. The
warning is informational only: it never pauses bots, cancels orders, or activates
the kill-switch, and it refreshes with the same five-second partial reload.

A **Needs attention** panel lists only non-archived bots that need operator action:
bots paused by the engine, bots missing a successful tick, and bots with an error.
Each row shows issue badges, the last tick and last successful tick, and the
engine's reason. The panel is hidden when no bot needs attention. Stale detection
requires a fresh heartbeat, a known engine start time, at least one full stale
threshold of engine uptime, and a running bot whose desired status is also running;
the threshold is `max(180 seconds, bot interval + 30 seconds)`. This keeps an
engine restart from briefly flagging every running bot as stale.

For an engine-paused paper bot the row offers a one-click **Resume** via
`bots.start`. For a live bot the row links to the bot detail page instead, so the
existing exact `<bot name> on <exchange>` confirmation and live gate are never
bypassed. A resume records a `bot.started` activity with `resumed: true`. The
button shows **Resuming...** until the next five-second poll or the redirect
completes; if the bot is still paused, the row reports that the engine has not
confirmed and allows a retry. Failed resumes render the control action's `action`
or `confirmation` error above the panel, so the error remains visible even if the
row changes state. Stale and error rows have no resume action.

The equity section accepts `?range=7`, `?range=30`, or `?range=90`; an omitted or
unknown range falls back to the configured 30-day default. It renders the selected
range's account equity curve with period return, peak equity, and maximum
drawdown. A range with no snapshots shows an empty state, and a range with fewer
than two snapshots is marked as collecting without fabricated metrics. The section
refreshes independently every minute, while the summary and recent activity keep
their five-second partial reload. See [analytics.md](analytics.md) for capture
cadence, formulas, and retention.

Feature test: `tests/Feature/DashboardTest.php`.

## Global engine status bar

Every authenticated page shows a compact status bar under the primary navigation.
It fetches `/health/engine` immediately and every ten seconds, parsing the JSON
body even on a 503. A module-level cache limits the tab to one request per ten
seconds across Inertia navigation, and an in-flight request is reused rather than
duplicated. The heartbeat includes `started_at`, `live_trading`, and
`market_data` (`configured`, `mode`, `degraded`, `reason`, `symbols`,
`last_message_at`); see [engine.md](engine.md) for the wire format.

The bar reports three independent states:

- **Engine:** **Engine up** means the heartbeat is fresh, not that every readiness
  check passed. **Engine down** shows the last-seen time, or **never**, and states
  that this is a warning only: bots are not paused, orders are not canceled, and
  the kill switch is not activated.
- **Feed:** **Feed live** for a healthy WebSocket feed, **REST fallback** when the
  feed is degraded or using REST, **Feed idle** when configured without symbols,
  **Feed off** when market data is not configured, and **Feed unknown** when the
  engine did not report the status.
- **Live:** **LIVE ON** in red when the engine reports the live gate open,
  **Live orders blocked** when it is closed, and **Live unknown** when the field
  is absent.

When the heartbeat is unavailable, the feed and live states are **unknown**
rather than stale claims from the last successful poll. The bar is a compact
global indicator; the dashboard's prominent heartbeat warning remains. It uses a
`role="status"` live region that announces only engine/feed/live state
transitions, not every poll.

## Bot list — `GET /bots` (`bots.index`)

Shows the signed-in user's active bots with name, symbol, exchange, mode, status,
open-order count, realized P&L, unrealized P&L, and last tick. The **Archived**
link opens the same page with `?archived=1`, listing only archived bots with a
per-row **Restore** action. Archived names are plain text rather than links, and
the archived view hides **Create bot** and the **Kill switch**. The page polls
every five seconds with `router.reload({ only: ['bots'] })`, preserving scroll,
component state, and the current query string. Bots belonging to other users never
appear. On the active view the header places a danger-styled **Kill switch**
button beside **Create bot**; it opens the confirmation page.

Feature tests: `tests/Feature/BotListTest.php`,
`tests/Feature/BotArchiveTest.php`.

## Create bot — `GET /bots/create` (`bots.create`) and `POST /bots` (`bots.store`)

The page has two modes. **Quick setup** asks for name, exchange, symbol, capital,
and center price, then derives the ladder from a preset. **Advanced** exposes the
full parameter set: strategy, fee rate, center price, level count, spacing
type/value, order quantity, and strategy-specific parameters (trailing distance,
martingale size multiplier/caps, dynamic volatility min/max/multiplier/hysteresis/interval).
Switching from quick to advanced keeps every derived value, so the bot can be
fine-tuned without retyping it.

Quick-setup presets are symbol-agnostic: percentage spacing maps to geometric
spacing, and order quantity is derived from
`capital x utilization / (levels per side x center price)`. The four presets are
`balanced`, `tight`, `wide` (simple grid) and `trailing` (trailing grid); they
stay within the default 20-order and 1000-quote risk limits at a 1000 capital
allocation. Martingale and dynamic grids remain advanced-only.

The form shows a live ladder preview before saving. It calls
`POST /bots/preview` (`bots.preview`), which asks the Go engine's internal plan
endpoint for levels, per-order size, notional totals, effective risk limits, and
advisories without writing anything. `GET /bots/market-data` (`bots.market-data`)
supplies the optional current-price button; when the exchange is unreachable the
form falls back to a manually entered center price.

- Mode defaults to **paper**.
- Live mode requires a stored exchange credential and the live configuration gate.
  The form explains that testnet and real-money live are different and that a
  paper bot never promotes to live automatically.
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

A completed backtest or sweep run can open this page with
`source_backtest_run_id`. The server derives the exchange, symbol, strategy,
capital, fee, strategy configuration, and protective exits from the run's
immutable snapshot. The form forces advanced paper mode, shows a source banner,
locks the source fields, and leaves only the name editable. The bot is created
idle with the user's current risk limits and never starts automatically. The
backtest run detail page and sweep run rows expose **Create paper bot** only when
the run is completed, owned by the viewer, and its snapshot can build a bot.

Feature tests: `tests/Feature/BotCreationTest.php`, `tests/Feature/BotPreviewTest.php`,
`tests/Feature/MarketDataTest.php`, `tests/Feature/BacktestToBotTest.php`,
`tests/Feature/BacktestToBotUiTest.php`.

## Bot detail — `GET /bots/{bot}` (`bots.show`)

Shows mode/status, realized and unrealized P&L, inventory, a grid ladder with
the plan side, the actual open-order side when it differs, price, and state,
open orders, recent fills, and risk limits. The mark price for
unrealized P&L is the bot's stored mark (latest tick), otherwise the average entry
price — the same source the bot list and dashboard use.

When the bot was created from a backtest, a **Backtest comparison** card links to
the source run and shows source and paper capital, net P&L, return percentages,
fees, fills, source maximum drawdown, and paper-minus-backtest deltas. Return
deltas are percentage points, not currency. A **Modified since creation** badge
appears when capital, fee rate, strategy configuration, or protective exits differ
from the source snapshot. Missing source metrics render as unavailable, zero
capital yields no return, and a bot with no source run shows no card. If the
source run is deleted, the link clears and the card disappears; the bot remains
usable. The card includes a caveat that periods, close-only backtest fills, live
paper data, current risk limits, and post-creation edits can differ.

The page polls every five seconds for
`bot, grid, openOrders, recentFills, inventory, pnl, canArchive`, plus
`backtestComparison` when the bot has a source run; a new fill appears on the next
poll.

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
  clears the last error. Resuming an engine-paused bot whose desired status is
  already running records a separate `bot.started` activity with `resumed: true`;
  a second start while an idle bot is still idle does not duplicate the event.
- **Pause** (`bots.pause`) pauses a running bot while preserving open orders.
- **Stop** (`bots.stop`) pauses/cancels every open order and marks the bot stopped.

For live bots, start requires a confirmation string equal to `<bot name> on <exchange>`,
the `TRADING_LIVE_ENABLED` gate, and enough free quote balance. Creating a live bot
requires the same phrase; the error is reported on `live_confirmation`. Testnet
comes first: a credential marked `testnet` or `TRADING_EXCHANGE_SANDBOX=true`
routes Binance orders to `https://testnet.binance.vision`, and a custom relay is
refused. Production real-money live needs an explicit operator yes. Missing or mismatched
confirmation is returned as a `confirmation` error; other failures are returned as
an `action` error and leave the prior state unchanged. Cross-user control is
denied.

Feature test: `tests/Feature/BotControlTest.php`.

## Archive and restore — `DELETE /bots/{bot}` (`bots.destroy`) and `POST /bots/{bot}/restore` (`bots.restore`)

The detail page shows an **Archive** button only when the bot can be archived:
both its status and desired status are stopped, it has no pending, open, or
partially-filled orders, and, for a live bot, the engine heartbeat is fresh. A
newly created bot starts idle, so it must be stopped before it can be archived. The
button asks for confirmation and warns that residual inventory is not sold.
Archiving is a soft delete: the bot disappears from the active list, backtest and
sweep selectors, the alert bot filter, dashboard counts, and kill-switch
selection. Its orders, fills, grids, strategy configuration, inventory ledger,
risk events, activity, alerts, backtests, and sweeps are retained, and historical
views still show the bot name. An archived bot is inert: detail, edit, activity,
control, order-cancel, and exit-guard routes return 404, and the engine does not
tick it.

The archived view restores a bot in place; it returns to the active list still
stopped, with no automatic start or state change. Only the owner may archive or
restore a bot, and only an archived bot can be restored. A rejected archive is
reported as an `action` error and leaves the bot unchanged.

Feature test: `tests/Feature/BotArchiveTest.php`.

## Edit — `GET /bots/{bot}/edit` and `PATCH /bots/{bot}`

The edit form renders the same ladder preview as creation, evaluated from the
bot's current risk limits. The preview refreshes as editable fields change, and
the page shows a field-by-field diff of the values that will change. Owners may
edit idle or stopped bots. A **running paper** bot may also be edited after an
explicit confirmation; running live bots and paused bots are read-only. Editable
fields are name, capital allocation, fee rate, and strategy parameters. Symbol,
exchange, mode, and strategy are immutable; submitted values for them are ignored.

For a running paper bot, the page shows a running banner, the field diff, the
ladder/order preview, and a confirmation checkbox. Saving without confirmation is
rejected and nothing changes. A confirmed structural strategy change or capital
change sets `reconcile_pending` and asks the engine to reconcile: the engine
cancels open orders, clears strategy state, and the next tick rebuilds the ladder.
Name-only and fee-only changes do not cancel orders or request a reconcile. If the
submitted values are unchanged, the page reports `No changes.` and persists
nothing.

Updates preserve the existing grid, strategy state, and inventory when the
structural strategy parameters (center, levels, spacing, quantity, or
strategy-specific limits) are unchanged. For an idle or stopped bot, changing
them sets a pending strategy reset; the next start clears strategy state before
trading. The edit page itself is viewable for any owned bot; live running and
paused bots show a read-only explanation and PATCH is denied. Activity records the
field diff and whether a strategy reset or reconcile was requested. If the engine
cannot be notified, the save is retained and an `engine` error explains that the
engine will retry the reconcile.

Feature tests: `tests/Feature/BotUpdateTest.php`, `tests/Feature/BotEditPageTest.php`,
`tests/Feature/BotEditUiTest.php`, `tests/Feature/BotPreviewTest.php`.

## Activity — `GET /bots/{bot}/activity` (`bots.activity`)

Shows the latest 100 activity entries and 100 risk events for one bot, newest
first, including severity, type, message, timestamp, and JSON context. Access is
limited to the bot owner.

Feature test: `tests/Feature/ActivityLogTest.php`.

## Settings — `GET /settings` (`settings.index`)

The Settings hub groups account configuration. It links to exchange credentials
and risk limits; each destination remains a separate, directly reachable page.
The Settings link in both primary navigations is active across these pages.

Feature test: `tests/Feature/SettingsUiTest.php`.

## Risk limits — `GET /settings/risk-limits` (`risk-limits.edit`) and `PATCH /settings/risk-limits` (`risk-limits.update`)

Edits the account-wide maximum position notional, maximum open orders, and daily loss limit. These apply to every bot without a bot-scope override. A successful save flashes `Risk limits updated.` and validation errors appear under each field. Reach the form through **Settings > Risk limits**.

## Kill switch — `GET /kill-switch` (`kill-switch.create`) and `POST /kill-switch` (`kill-switch.store`)

Reach the confirmation page from the danger-styled **Kill switch** button beside
**Create bot** on the bot list or from the dashboard quick link. The confirmation
page shows how many bots and open orders will be affected. Submitting the exact
phrase `KILL SWITCH` pauses every non-stopped bot for the user and cancels all open
orders in parallel. Per-bot cancellation failures are reported; the bots are
paused immediately regardless.

Feature test: `tests/Feature/KillSwitchUiTest.php`.

## Exchange credentials — `/settings/credentials` resource

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
state. An alert for an archived bot shows the bot name as plain text. An optional
bot filter narrows the list to one active owned bot; another user's bot id, or an
archived bot id, is rejected with 404.

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
  per run with its center, spacing, levels, status, return, maximum drawdown, end
  reason, and an action. Each row links to the normal backtest detail page.
  Completed rows whose snapshot can build a bot also show **Create paper bot**,
  which opens `bots.create` with that run as the source; failed or incomplete rows
  do not. While any run is pending or running, the page polls the sweep, runs,
  compare URL, and active flag. When two to four runs have completed, it shows
  **Compare completed**, which opens the existing `backtests.compare` page.
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
| Bot health triage | `dashboard` | `BotHealthTest` |
| Engine-paused resume | `bots.start` | `BotActionsTest` |
| Settings hub | `settings.index` | `SettingsUiTest` |
| Bot list | `bots.index` | `BotListTest` |
| Create bot | `bots.create`, `bots.store` | `BotCreationTest` |
| Backtest to bot | `bots.create`, `bots.store` | `BacktestToBotTest`, `BacktestToBotUiTest` |
| Bot detail | `bots.show` | `BotDetailTest` |
| Backtest comparison | `bots.show`, `backtests.show` | `BotDetailTest`, `BacktestComparisonTest` |
| Protective exits | `bots.exit-guard.update` | `BotExitGuardTest` |
| Start/pause/stop | `bots.start`, `bots.pause`, `bots.stop` | `BotControlTest` |
| Archive/restore | `bots.destroy`, `bots.restore` | `BotArchiveTest` |
| Activity | `bots.activity` | `ActivityLogTest` |
| Kill switch | `kill-switch.create`, `kill-switch.store` | `KillSwitchUiTest` |
| Credentials | `exchange-credentials.*` | `ExchangeCredentialUiTest` |
| Alerts | `alerts.index`, `alerts.read`, `alerts.read-all` | `AlertInboxTest` |
| Backtest sweeps | `backtest-sweeps.index`, `backtest-sweeps.create`, `backtest-sweeps.store`, `backtest-sweeps.show` | `BacktestSweepDashboardTest` |
| Authorization | all bot/credential routes | `AuthorizationTest` |
