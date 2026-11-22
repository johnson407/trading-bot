# Dashboard

The dashboard is an authenticated Inertia.js + Vue 3 application. All pages below
require `auth` and (except profile/auth pages) verified email. Every bot,
credential, alert, and control action is authorized against the authenticated user.

## Authentication

Breeze provides registration, login, password reset, email verification, and
profile management. Guests are redirected to `/login`; unauthenticated access to a
bot, credential, or control endpoint is rejected. `AuthorizationTest` covers guest
redirects, owner access, and cross-user denial.

## Bot list — `GET /bots` (`bots.index`)

Shows the signed-in user's bots with name, symbol, exchange, mode, status,
open-order count, realized P&L, unrealized P&L, and last tick. The page polls every
five seconds with `router.reload({ only: ['bots'] })`, preserving scroll and
component state. Bots belonging to other users never appear.

Feature test: `tests/Feature/BotListTest.php`.

## Create bot — `GET /bots/create` (`bots.create`) and `POST /bots` (`bots.store`)

The form captures name, exchange (from the `TRADING_EXCHANGES` allowlist), symbol, strategy, mode, capital
allocation, fee rate, center price, level count, spacing type/value, order
quantity, and strategy-specific parameters (trailing distance, martingale size
multiplier/caps, dynamic volatility min/max/multiplier/interval).

- Mode defaults to **paper**.
- Live mode requires a stored exchange credential and the live configuration gate.
- Invalid symbol, capital, levels, spacing, quantity, fee, strategy, or missing
  strategy-specific parameters are rejected with field errors.
- Creation also creates the strategy configuration and user/bot risk limits, and
  records `bot.created`.

Feature test: `tests/Feature/BotCreationTest.php`.

## Bot detail — `GET /bots/{bot}` (`bots.show`)

Shows mode/status, realized and unrealized P&L, inventory, a grid ladder with
price/side/state, open orders, recent fills, and risk limits. The mark price for
unrealized P&L is the bot's stored mark (latest tick), otherwise the average entry
price — the same source the bot list and dashboard use. The page polls every five seconds for
`bot, grid, openOrders, recentFills, inventory, pnl`; a new fill appears on the
next poll.

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

Only the owner may edit, and only while the bot is idle or stopped. Editable
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

## Polling and freshness

List and detail pages poll every five seconds, meeting the MVP's five-second
status target without WebSockets. Polls request only the changing props and
preserve scroll/state. Control actions use normal Inertia POSTs with CSRF
protection; a failed action never leaves a partial state change visible.

## Test map

| Flow | Route | Test |
| --- | --- | --- |
| Bot list | `bots.index` | `BotListTest` |
| Create bot | `bots.create`, `bots.store` | `BotCreationTest` |
| Bot detail | `bots.show` | `BotDetailTest` |
| Protective exits | `bots.exit-guard.update` | `BotExitGuardTest` |
| Start/pause/stop | `bots.start`, `bots.pause`, `bots.stop` | `BotControlTest` |
| Activity | `bots.activity` | `ActivityLogTest` |
| Kill switch | `kill-switch.create`, `kill-switch.store` | `KillSwitchUiTest` |
| Credentials | `exchange-credentials.*` | `ExchangeCredentialUiTest` |
| Alerts | `alerts.index`, `alerts.read`, `alerts.read-all` | `AlertInboxTest` |
| Authorization | all bot/credential routes | `AuthorizationTest` |
