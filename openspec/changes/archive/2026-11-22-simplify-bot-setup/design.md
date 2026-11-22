# Design

## Context

See `proposal.md` for motivation. The constraints that shape this design:

- Strategies are pure (`StrategyInput` → `StrategyDecision`), so grid levels can be generated outside a tick by calling `initialize()` / `getGridLevels()`.
- `GeneratesGridLevels::buildLevels()` already rejects geometric spacing ≥ 1, non-positive prices, and duplicates, but only when a bot first initializes.
- `RiskGuard` resolves limits as bot scope → user scope → config defaults and enforces them per order at tick time. `RiskLimitService::effectiveForBot()` is non-mutating; `forUser()` creates a row.
- `ExchangeClient::market()` returns last price, price precision, amount precision, minimum amount, and minimum notional over the public client.
- `BotTickPipeline::placeIntent()` silently skips orders below exchange minimums (`order.min_amount`, `order.min_notional`), which is exactly the failure the preview must make visible.
- Money math uses bcmath `Decimal`; frontend money display uses `resources/js/Support/money.js`.
- `BotControlFeedbackTest` reads `Create.vue` as text and asserts specific strings; `DemoWalkthroughTest` posts the existing payload shape.

## Goals / Non-Goals

**Goals:**
- One evaluation path shared by preview, create, and update, so displayed values and saved values cannot diverge.
- Quick setup that needs only name, symbol, exchange, capital, preset, and mode, without hiding the mechanics from advanced users.
- Warnings that teach (limits, unfunded sells, minimums) without becoming hard errors.

**Non-Goals:**
- Changing risk enforcement, live gating, confirmation phrases, or the tick pipeline.
- Adding new strategies or new strategy parameters.
- Auto-starting a bot after creation.
- Putting martingale or dynamic strategies in the quick-setup preset list.
- Reworking the backtest setup flow.

## Decisions

### One evaluator, three consumers

Add a domain service (`GridPlanEvaluator`) that accepts a strategy key plus a configuration array and returns levels, per-level quantity/notional, totals, and field-keyed problems. It delegates level generation to the registered strategy, so arithmetic/geometric semantics stay in one place.

- **Why:** previewing and saving through separate math guarantees drift and duplicate validation rules.
- **Alternative rejected:** validating geometry inline in the form request — it would duplicate `buildLevels()` and miss strategy-specific behavior.
- **Alternative rejected:** computing the ladder in JavaScript — floats and a second implementation of `Decimal` semantics violate the repo's precision rules.

### Preview and save share the submitted values

Quick-setup presets are resolved server-side and returned as concrete values (center price, levels, spacing type, spacing value, quantity). The client submits those concrete values through the existing `StoreBotRequest` payload. `preset_key` is an optional input used only for preview/derivation.

- **Why:** the store contract stays field-compatible (`DemoWalkthroughTest` and any API-style caller submit the same shape), and `StoreBotRequest` does not need a second validation pass for derived fields.
- **Intentional behavior change:** callers that submit geometry or quantities which could never run are now rejected before save, so "compatible" refers to the payload shape, not to acceptance of every previously accepted value.
- **Trade-off:** if a client posts a hand-edited payload that does not match the preset, the preset is irrelevant — acceptable, because presets are a convenience, not an authorization boundary.

### Preview transport

`POST /bots/preview`, authenticated, throttled with `throttle:bot-control`, accepting the same fields as store minus `live_confirmation`. It returns `plan`, `warnings`, `limits`, and `market`. It writes nothing.

- **Why POST:** the payload is large and multi-field; query strings would be unreadable and length-limited.
- **Alternative rejected:** recomputing the page server-side on every keystroke — no live feedback.
- **Rate limiting:** reuse the existing bot-control throttle so a debounced preview cannot be used to hammer the exchange.

### Market data: one service, two consumers

A small resolver (cached briefly per exchange+symbol) wraps `ExchangeClient::market()`. The preview calls it to check exchange minimums; a separate `GET /bots/market-data` action is used by the "use current price" control. Both degrade to "market data unavailable" on failure.

- **Why one resolver:** the anchoring button and the minimum checks must agree on price and precision.
- **Trade-off:** a preview can incur an exchange call; the short cache and graceful failure bound the cost. Preview never fails because of the exchange.
- **Minimum checks are conditional:** when market data is unavailable, minimum-amount/notional problems become warnings, not hard stops — an offline exchange must not block bot creation.

### Hard stops are intrinsic; risk-limit excess is advisory

Hard stops: geometric spacing ≥ 1, non-positive level price, duplicate level prices, and (with market data) below-minimum quantity or notional. Warnings: order count over `max_open_orders`, buy notional over `max_position_notional`, center far from market, unfunded sells.

- **Why:** the first group can never place a valid order; the second group is either partially workable or caused by a limit the user can raise on the Risk Limits page. The user explicitly chose warn-don't-block for risk limits.
- **Limits source:** add `effectiveForUser(User)` beside `effectiveForBot()` so preview resolves user scope → defaults without creating a risk-limit row.
- **Alternative rejected:** blocking on risk limits — it forces a detour for users about to raise the limit and duplicates enforcement already applied per order.

### Percentage spacing maps to geometric

Quick setup exposes "spacing percent", which maps directly to geometric spacing (`0.4` → `spacing_value = 0.004`). Arithmetic spacing remains an advanced-only choice.

- **Why:** percentage spacing is what newcomers mean by "levels 0.4% apart", and geometric spacing already has exactly that semantics.
- **Alternative rejected:** adding a new spacing mode to the engine — unnecessary surface; the mapping is lossless.

### Quantity derivation

`order_quantity = capital_allocation × utilization / (levels_per_side × center_price)`, computed with `Decimal`. Presets default utilization to `0.8` and levels to ≤ 10 per side.

- **Why:** buys are funded by quote inventory; sells are funded by fills. Deriving from capital and center makes both symbol-agnostic.
- **Cap rationale:** 10 levels per side is exactly the default `max_open_orders` of 20, so quick setup cannot exceed the default order cap.
- **Alternative rejected:** deriving from the risk limit — it would couple presets to user-edited limits and change preset meaning when limits change.

### Preset catalog lives in backend config

`config/bot_presets.php` defines key, label, description, strategy, levels, spacing type/percent, utilization, and strategy-specific defaults. `BotController::create()` exposes it; a Pest test derives every preset at a reference price/capital and asserts the result stays within default limits.

- **Why:** testable in PHP, shared by preview and UI, and easy to extend as strategies change.
- **Alternative rejected:** frontend constants — untestable in the existing suite and prone to drifting from validation.

### UI shape

`Create.vue` gets a quick/advanced segmented control, a wizard-free single form, and an extracted preview component. Quick mode renders name, symbol, exchange, capital, preset, mode, and the live-price control; advanced renders every current field. Switching modes copies derived values into the advanced fields. `Edit.vue` reuses the preview component for structural edits.

- **Why:** one page avoids a multi-step wizard and keeps the live confirmation and error surfaces intact.
- **Test constraint:** preserve `live_confirmation: ''`, `form.errors.live_confirmation`, `` `${form.name} on ${form.exchange}` ``, and `to confirm live trading` in `Create.vue`, or update `BotControlFeedbackTest` in the same change.
- **Dynamic grids:** hide `spacing_type`/`spacing_value` (they are ignored by the strategy) while still accepting them for payload compatibility. Martingale and dynamic stay advanced-only: their caps and volatility inputs need more guidance than a one-line preset can carry.

### Fee default

New bots default `fee_rate` from `config('trading.paper.fee_rate')` in both the form initial state and `CreateBot`, instead of hardcoded `'0'`.

## Risks / Trade-offs

- **Exchange latency or rate limits during preview** → short-TTL cache, debounce, bot-control throttle, and graceful degradation to "market data unavailable".
- **Preview and saved bot differ because the user edits advanced fields after preview** → the preview recalculates on every relevant change; submit sends explicit values, so the saved bot always matches the last visible form state.
- **Presets become stale as risk defaults change** → the preset test derives against `config('risk.defaults')` rather than hardcoded numbers.
- **`Create.vue` grows and becomes harder to maintain** → extract preview and preset selector into components; keep the form template flat.
- **A user ignores warnings and creates a partially blocked bot** → warnings name the limit and link to Risk Limits; runtime risk events remain the backstop.
- **Ticker fetch is unavailable in tests/offline** → market data is optional everywhere; tests cover both present and absent branches with a fake exchange client.
- **Percent-to-fraction rounding** → all conversions happen in `Decimal` and are asserted in tests; the frontend only displays server-returned strings.

## Migration Plan

No data migration. The change is additive: a new config file, a new domain service, a new route, and a new preview component. Existing bots and payloads are untouched. Rollback is a code revert; `preset_key` is never persisted, so no stored state needs cleanup.
