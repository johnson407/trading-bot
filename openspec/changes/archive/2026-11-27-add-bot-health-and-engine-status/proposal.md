# Proposal

## Why

Issue #17 asks for visibility into bot health and engine status. Today the dashboard shows aggregate bot counts but not which bot is stale, which bot the engine paused and why, or whether the engine, market-data feed, and live gate are actually in the expected state. A stale-tick pause (#12) is effectively invisible until the user opens the bot list, and resuming an engine-paused bot is silent because its `desired_status` was already `running`.

## What Changes

- Add a dashboard "Needs attention" triage panel listing only the user's non-archived bots that need attention: running bots that are stale, bots paused by the engine (`status = paused` with `desired_status = running`), and bots with a non-blank recorded error. Show last tick, last successful tick, and the reason. Only engine-paused rows get a one-click resume; stale and error rows are informational and link to the bot page.
- Resume safely: paper engine-paused bots get a one-click resume; live bots link to the bot detail page's existing exact-phrase confirmation form. The live gate, credential check, balance check, and confirmation phrase are never bypassed.
- Record a user activity entry when a resume is issued for an engine-paused bot, so the action appears in the activity log.
- Extend the engine heartbeat with the engine start time, the live-trading gate state, and a market-data snapshot (configured, mode, degraded, reason, symbol count, last message time). New fields are optional so an older engine still reports a valid heartbeat.
- Add a global status bar in the authenticated header showing engine heartbeat state (with last seen), feed state (live / REST fallback / idle / off / unknown), and live-trading state (live orders blocked / LIVE ON / unknown). It polls `/health/engine` at most once every ten seconds and remains warning-only.
- Correct the `Scheduled maintenance` stale-threshold wording to match the engine's implemented `max(180 seconds, bot interval + 30 seconds)` rule.
- Update `docs/dashboard.md`, `docs/engine.md`, `docs/runtime.md`, `engine/README.md`, and the affected tests.

Out of scope: issue items 3-6. Item 6 conflicts with the current "Bot editing" requirement that running or paused bots SHALL NOT be editable, so it needs a separate policy decision before any implementation.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: The engine heartbeat includes the engine start time, live-trading gate state, and market-data status, while remaining secret-free and warning-only. The scheduled-maintenance stale threshold is clarified to match the implemented interval-plus-30-seconds rule.
- `trading-dashboard`: The dashboard shows per-bot health and a safe resume path for engine-paused bots, and the authenticated header shows global engine, feed, and live-trading status.

## Impact

- **Go engine:** `engine/internal/heartbeat` payload/snapshot, minimal `CheckFresh` decoding, and wire tests; `engine/main.go` snapshot provider wiring with explicit `cfg.MarketData.Configured()`; mapping of `marketdata.Feed.Status()` into heartbeat primitives; a small `applyDesired` consistency fix that refreshes `last_successful_tick_at` when a bot transitions to running.
- **Laravel:** `app/Services/Engine/EngineHeartbeat.php` optional-field parsing; `app/Domain/Bot/BotHealth.php` triage computation; `app/Http/Controllers/DashboardController.php` bot-health rows; `app/Actions/StartBot.php` engine-paused resume activity; `tests/Support/FakeEngineHeartbeat.php`.
- **Frontend:** `resources/js/Pages/Dashboard.vue` triage panel and resume error/pending state; `resources/js/Components/EngineStatusBar.vue`; `resources/js/Support/engineStatus.js` display and polling helper; `resources/js/Layouts/AuthenticatedLayout.vue`.
- **Docs/tests:** `docs/dashboard.md` (executable fixture asserted by `DashboardDocsTest`), `docs/engine.md`, `docs/runtime.md`, `engine/README.md`, `tests/Feature/DashboardTest.php`, `tests/Feature/EngineTransportTest.php`, `tests/Feature/BotActionsTest.php`, Go heartbeat/runtime tests, and JS helper tests.
- **Risk:** no changes to money arithmetic, risk limits, order placement, or the live gate itself. The only engine behavior change is aligning the desired-running transition's stale grace with the existing `applyStatus` path.
