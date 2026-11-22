# Tasks

## 1. Domain evaluation

- [x] 1.1 Add a `GridPlanEvaluator` domain service that takes a strategy key and configuration, generates levels through the registered strategy, and returns levels, per-level quantity and notional, totals, and field-keyed problems; verify with a new unit test covering arithmetic and geometric ladders plus each rejection case.
- [x] 1.2 Add `config/bot_presets.php` with keyed presets (label, description, strategy, levels per side, spacing type, spacing percent, utilization, strategy-specific defaults); verify a Pest test derives every preset at a reference price and capital and asserts its order count and buy notional stay within `config('risk.defaults')`.
- [x] 1.3 Add decimal derivation helpers for percent-to-fraction spacing and `quantity = capital x utilization / (levels x center)`; verify a unit test asserts exact bcmath strings and that float input is rejected.

## 2. Preflight validation

- [x] 2.1 Wire evaluator hard stops into `StoreBotRequest` (geometric spacing ≥ 1, non-positive level price, duplicate level price, below-minimum quantity/notional when market data exists); verify `tests/Feature/BotCreationTest.php` gains a case per rejection and asserts no bot row is created.
- [x] 2.2 Wire the same hard stops into `UpdateBotRequest`; verify a test rejects invalid geometry on update and leaves the bot and its strategy configuration unchanged.
- [x] 2.3 Add a non-mutating `effectiveForUser(User)` to `RiskLimitService` beside `effectiveForBot()`; verify a test asserts user scope overrides defaults and that resolving limits writes no `risk_limits` row.
- [x] 2.4 Build advisory warnings for order count over the open-order cap, buy notional over the position cap, center price outside the market band, and unfunded sells; verify a test asserts each warning key is returned and that none of them produces a validation error.
- [x] 2.5 Default `fee_rate` from `config('trading.paper.fee_rate')` in `CreateBot` when the field is omitted; verify a test sets a non-zero config value and asserts the stored bot uses it.

## 3. Preview and market data endpoints

- [x] 3.1 Add authenticated, `throttle:bot-control` `POST /bots/preview` returning plan, warnings, effective limits, and market data; verify a feature test covers the guest redirect, the response shape, throttling, and that repeated previews leave bots, grids, orders, and risk limits unchanged.
- [x] 3.2 Add the market data resolver (short per exchange+symbol cache over `ExchangeClient::market()`) and `GET /bots/market-data`; verify tests with the fake exchange client cover success (price, precision, minimums) and failure (unavailable, preview still valid).
- [x] 3.3 Verify preview/save agreement with a test that previews a setup, stores the same values, and asserts the saved grid levels and totals match the previewed plan.

## 4. Frontend setup flow

- [x] 4.1 Extract a `PlanPreview` component that renders levels, order size, totals, limit usage, and warnings; verify a JS unit test covers money formatting of notionals and warning rendering.
- [x] 4.2 Add quick/advanced modes, a preset selector, and a "use current market price" control to `Create.vue`, keeping derived values when switching to advanced; verify `BotControlFeedbackTest` still passes and a JS test asserts the toggle preserves values and the live-confirmation strings are intact.
- [x] 4.3 Reuse `PlanPreview` on `Edit.vue` for structural edits; verify a feature test asserts the edit page receives the preview data and `npm run build` succeeds.
- [x] 4.4 Show human strategy labels and descriptions instead of raw keys, and hide `spacing_type`/`spacing_value` for dynamic grids; verify a JS test covers the labels and that a dynamic-grid submission without spacing fields is still accepted.
- [x] 4.5 Provide a graceful "market data unavailable" state and keep manual center price submittable; verify a JS test asserts the form stays valid and shows the notice when the market request fails.

## 5. Documentation

- [x] 5.1 Update the create-bot section of `docs/dashboard.md` to describe quick setup, advanced mode, preview, and advisory warnings; verify `DashboardDocsTest` passes.
- [x] 5.2 Update `docs/bot-parameters.md` with the preset catalog, derivation formulas, and the hard-stop/warning split, and link it from the setup form; verify `DashboardDocsTest` passes and every config key and route named in the docs exists.

## 6. Integration verification

- [x] 6.1 Run the full PHP suite and confirm it passes with the test environment overrides from `AGENTS.md`.
- [x] 6.2 Run PHPStan level 5 and Pint and confirm both are clean.
- [x] 6.3 Run `npm run build` and `npm run test:js` and confirm both pass.

## Workflow follow-up

- Archive the change once implementation and review requirements are satisfied.
- Verify the archived spec merge preserves the existing `trading-dashboard` and `strategy-engine` requirements.
