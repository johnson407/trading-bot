# Design

## Context

The engine already stores `bots.exchange`, but `CcxtExchangeClient::exchange()` returns `config('trading.default_exchange')` and `market()` takes only a symbol, so every bot talks to the same exchange. The dashboard is the Breeze placeholder: `AuthenticatedLayout.vue` links only to Dashboard, `Dashboard.vue` says "You're logged in!", `HandleInertiaRequests` shares only `auth`, and controllers flash `status` messages that no page renders. `LiveStartConfirmation::matches()` compares only the bot name, and `live_confirmation` on creation is accepted without verification. Routes expose `index`, `create`, `show`, and controls, but no edit or update.

This change closes those four PRD-visible gaps without touching the trading safety spine. See `proposal.md` for motivation and `specs/` for required behavior.

## Goals / Non-Goals

**Goals:**

- Make `bots.exchange` authoritative for market data, balances, and order operations.
- Keep the exchange surface pluggable and operator-controlled through an allowlist.
- Give every dashboard action visible success or failure feedback.
- Require an unambiguous live confirmation that names both the bot and its exchange.
- Allow safe, bounded bot editing without invalidating persisted grid or strategy state.

**Non-Goals:**

- Adding exchange-specific adapters, credentials per sub-account, or exchange capability matrices.
- Changing the order, fill, risk, or reconciliation pipelines.
- Real-time UI (WebSocket); the existing five-second polling remains the freshness mechanism.
- Editing identity fields (symbol, exchange, mode) or editing a running bot.

## Decisions

### Per-bot exchange threading

Do not change the `ExchangeClient` interface. Bind the exchange at construction instead:

- `CcxtExchangeClient` gains an optional constructor argument `?string $exchange = null`. When null it falls back to `config('trading.default_exchange')`, preserving existing behaviour and tests. `exchange()` returns the bound value; `createClient()` instantiates `\ccxt\{strtolower($exchange)}`.
- `PaperExchangeClient` gains an optional `?string $exchange = null` (or receives a pre-bound public-data client from the factory) so paper market data comes from the bot's exchange.
- `ExchangeClientFactory::forBot()` builds the correct client per bot:

```php
if ($this->live !== null) return $this->live;   // test override
if ($this->paper !== null) return $this->paper; // test override

$public = new CcxtExchangeClient(exchange: $bot->exchange);

return $bot->isLive() ? $public : new PaperExchangeClient($public);
```

The constructor overrides stay so the existing test suite can bind `new ExchangeClientFactory($fake, $fake)` unchanged. Production no longer resolves a singleton client and therefore no longer shares one global exchange.

**Why not add `Bot` to `market()`:** that would change the interface, every fake, and every call site for no behavioural gain. Constructing a small client per bot tick keeps the contract stable and lets the exchange travel with the client. The per-tick construction cost is already accepted by the runtime; caching can come with the capacity work.

### Exchange allowlist

Add to `config/trading.php`:

```php
'exchanges' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TRADING_EXCHANGES', 'binance')),
))),
```

`StoreBotRequest` validates `exchange` with `Rule::in(config('trading.exchanges'))`. `BotController@create` passes the same list to the page. An unknown exchange produces a normal field error. Operators widen the list with `TRADING_EXCHANGES=binance,kraken,bybit`; the thin CCXT wrapper then instantiates the matching class at runtime, and an unsupported class still surfaces the existing `ExchangeException::Unsupported`.

**Why an allowlist rather than "any CCXT class":** the PRD requires other exchanges to be selectable, but silently accepting arbitrary class names would let a typo reach the exchange layer. The allowlist is one env var away from the full CCXT catalogue and keeps the demo predictable.

### Dashboard feedback and navigation

- `HandleInertiaRequests::share()` adds `'flash' => ['status' => fn () => $request->session()->get('status')]`. Validation `errors` are already shared by the Inertia middleware; pages simply never rendered `errors.confirmation`.
- A small `FlashMessage.vue` component reads `$page.props.flash.status` and renders a dismissible success banner; `AuthenticatedLayout` includes it once, so every page benefits.
- Navigation adds Bots, Exchange credentials, and Kill switch beside Dashboard in both the desktop and responsive menus.
- `Dashboard.vue` becomes a summary: bot counts by state, total realized and unrealized P&L, the most recent activity, and links to the main sections. It reuses the existing five-second polling pattern.
- `Bots/Index.vue` adds Exchange and Unrealized P&L columns. The summary resource exposes `exchange` and `unrealized_pnl`; `BotController@index` computes unrealized P&L with the ledger and each bot's stored mark, matching the detail page.
- `Bots/Show.vue` renders `errors.confirmation`, `errors.action`, and a link to `bots.activity`.

### Live confirmation format

The required phrase is:

```text
<bot name> on <exchange>
```

`LiveStartConfirmation::matches()` compares against `"{$bot->name} on {$bot->exchange}"`. Both entry points enforce it:

- **Creation:** `StoreBotRequest` rejects a live bot whose `live_confirmation` does not equal the computed phrase, adding the error on `live_confirmation`.
- **Start:** `StartBot`/`LiveModeGuard` continue to throw `LiveConfirmationRequiredException`; the controller already maps it to `errors.confirmation`.

`Bots/Create.vue` shows the field only when mode is live, with helper text stating the exact phrase. `Bots/Show.vue` shows the same helper text on the start confirmation control. The phrase is case-sensitive and whitespace-trimmed, matching the existing confirmation value object.

### Bot editing

- Routes: `GET bots/{bot}/edit` → `edit`, `PATCH bots/{bot}` → `update`, both inside the authenticated group and the `throttle:bot-control` middleware.
- `BotPolicy@update`: the user owns the bot **and** its status is `Idle` or `Stopped`. Running or paused bots return `403` through the existing authorization response.
- `UpdateBotRequest` accepts only: `name`, `capital_allocation`, `fee_rate`, and the strategy-specific parameter set already handled by `StoreBotRequest::strategyConfiguration()`. It never accepts `symbol`, `exchange`, `mode`, or `strategy_key`.
- `BotController@update` updates the bot fields and the existing `StrategyConfig` in one transaction, records `bot.updated` activity, and redirects to the detail page with a flash.
- `Bots/Edit.vue` reuses the create form's strategy field groups, displays symbol, exchange, mode, and strategy key as read-only context, and submits with `form.patch`.
- A bot that is not idle shows an explanatory message instead of an edit form rather than a bare 403 page.

**Why immutable identity:** symbol, exchange, and mode determine credentials, market data, grid prices, and persisted strategy state. Mutating them under an existing grid would silently reinterpret open orders. A user who wants a different identity creates a new bot; this matches the PRD's "create bot" flow and avoids a state-migration feature that the MVP does not need.

### Data and compatibility

No migrations. `bots.exchange` already exists and is indexed through normal model usage. Existing bots default to `binance` and behave exactly as before when `TRADING_EXCHANGES=binance`. Existing `LiveStartConfirmation` call sites keep their signature; only the compared string changes, and its tests are updated to the new phrase.

## Risks / Trade-offs

- **Phrase change breaks existing user habits.** Acceptable: live confirmation is a safety control, and the PRD explicitly requires naming the bot and exchange.
- **Allowlist blocks an exchange the PRD calls "any CCXT exchange".** Mitigated by documenting `TRADING_EXCHANGES`; the default remains Binance, and widening is configuration, not code.
- **Per-tick client construction.** Already the current behaviour for the singleton; moving construction into the factory does not add a new cost. Caching is deferred to the capacity work.
- **Edit while paused.** Deliberately blocked; a paused bot still has live open orders and persisted grid state, so editing it would produce an inconsistent view of what is running.

## Migration Plan

1. Ship configuration and exchange threading behind the default allowlist; all existing bots continue on Binance.
2. Ship dashboard and confirmation changes; users see the new phrase requirement immediately.
3. Ship edit routes; no data migration required.
4. Rollback is code-only: revert the routes/UI and set `TRADING_EXCHANGES=binance`. No schema or stored-state changes are introduced.
