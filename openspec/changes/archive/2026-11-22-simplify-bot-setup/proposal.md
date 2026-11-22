# Proposal

## Why

Creating a bot currently asks for twelve always-visible fields plus up to four strategy-specific ones, several of them raw implementation values (absolute spacing, base-asset quantity, raw strategy keys) with no explanation of how they interact. Worse, the form accepts configurations that cannot work: geometric spacing of `1` or more is rejected only when the bot first ticks, grids with more levels than the open-order cap have their extra orders rejected one at a time as warning risk events after start, and quantities below exchange minimums are skipped order by order with warnings. New users either abandon setup or create a bot that fails after the fact. The strategies, `MarketData` precision/minimums, and effective risk limits are all available before save, so the setup flow can show what will be created and refuse only what can never run.

## What Changes

- Add a **quick setup** default view that asks for name, symbol, exchange, capital allocation, center price, preset, and mode; all existing fields remain available behind an **Advanced** toggle, pre-filled with any values already derived.
- Add a backend-owned **preset catalog** with symbol-agnostic percentage spacing and quantities derived from capital, level count, and a utilization factor, sized to stay inside default risk limits.
- Add a **server-computed preview** of the resulting ladder: levels, per-order size and notional, order count versus the open-order cap, buy notional versus the position cap, and the market price, price/amount precision, and exchange minimums when the ticker is available.
- Add **preflight evaluation** shared by preview, create, and update. It hard-stops only configurations that can never place an order; everything else is a warning.
- **Risk-limit excess warns and never blocks creation.** Limits stay enforced at execution time and remain editable on the Risk Limits page; the warning links there.
- Add **market price anchoring** with an optional "use current market price" action; when the ticker is unavailable the form still works with manual values.
- Default `fee_rate` from `config('trading.paper.fee_rate')` instead of a hardcoded `0`.
- Show human strategy labels and short descriptions instead of raw identifiers, and stop requiring `spacing_type`/`spacing_value` for dynamic grids, which ignore them.
- Update `docs/bot-parameters.md` and link it from the form as the full parameter guide.
- Keep the stored bot payload shape unchanged so existing API-style callers submit the same fields, with one intentional behavior change: setups that could never run, such as invalid geometry or orders below exchange minimums, are now rejected before save rather than failing after the bot starts.

## Capabilities

### New Capabilities

- `bot-provisioning`: preset catalog, derived defaults, preflight plan evaluation (hard stops versus warnings), market-data anchoring, and the preview contract used by the create and edit flows.

### Modified Capabilities

- `trading-dashboard`: the bot creation requirement expands to a quick-setup-first flow with progressive disclosure, a visible ladder preview, and non-blocking guidance/warnings alongside the existing validation and paper default.
- `strategy-engine`: adds a creation-time configuration validity requirement so geometry that cannot produce a valid ladder is rejected before save, while strategy initialization remains the runtime authority.

## Impact

- `app/Http/Requests/StoreBotRequest.php`, `app/Http/Requests/UpdateBotRequest.php`, `app/Http/Requests/Concerns/BuildsStrategyConfiguration.php`
- New domain service for plan evaluation and preset resolution, plus `config/bot_presets.php`
- `app/Http/Controllers/BotController.php` (preview endpoint, create payload, strategy labels), `routes/web.php`
- `app/Actions/CreateBot.php` (fee-rate default)
- `app/Domain/Risk/RiskLimitService.php` (non-mutating effective user limits for preview)
- `resources/js/Pages/Bots/Create.vue`, `resources/js/Pages/Bots/Edit.vue`, new preview component
- `docs/bot-parameters.md`, `docs/dashboard.md`, and tests in `tests/Feature/BotCreationTest.php`, `tests/Feature/BotControlFeedbackTest.php`, `tests/Feature/DemoWalkthroughTest.php`, plus new plan-evaluation and preview tests
