# Tasks

## 1. Engine heartbeat snapshot and stale grace

- [x] 1.1 Extend `engine/internal/heartbeat` with `Snapshot` and `MarketData`, additive `started_at`/`live_trading`/`market_data` payload fields, snapshot-based `Write`/`Run`, and a `CheckFresh` that decodes only `ts`; serialize `live_trading`, `configured`, and `degraded` even when false; remove `WritePlaceholder` and update `heartbeat_test.go` plus the wire tests, including a valid `ts` with malformed optional fields and false-boolean assertions; verify with `make -C engine test`.
- [x] 1.2 Capture the engine start time in `engine/main.go`, add a helper that maps `marketFeed.Status()`, `cfg.MarketData.Configured()`, `cfg.LiveTrading`, and the running count into a `heartbeat.Snapshot`, and use it for the initial and periodic writes; add `engine/main_test.go` coverage for configured-but-idle versus not-configured; verify with `make -C engine test` and the Docker Go runner's `go build ./...`.
- [x] 1.3 Align the `applyDesired` running transition with `applyStatus`: clear `last_tick_at`, set `last_successful_tick_at = now()`, reset `consecutive_failures`, and clear `last_error`; add a runtime test proving a late-starting bot gets the full stale grace; verify with `make -C engine test`.
- [x] 1.4 Document the new heartbeat fields, false-boolean serialization, `CheckFresh` behavior, and unknown-on-old-engine behavior in `docs/engine.md`, `docs/runtime.md`, `engine/README.md`, and the heartbeat package doc; verify with the focused docs tests and a read-through.

## 2. Laravel heartbeat parsing

- [x] 2.1 Extend `EngineHeartbeat::status()` with optional `started_at`, `live_trading`, and `market_data` parsing and a precise PHPDoc array shape, keeping availability based only on `ts`; make `configured` and `degraded` nullable so malformed members render unknown rather than off/live; add `EngineTransportTest` cases for present, absent, malformed, and partial fields; verify with the focused `EngineTransportTest`.
- [x] 2.2 Add `$startedAt`, `$liveTrading`, and `$marketData` defaults to `tests/Support/FakeEngineHeartbeat.php` and include them in `status()`; verify `EngineTransportTest` and `DashboardTest` still pass.
- [x] 2.3 Add `Cache-Control: no-store` to the `/health/engine` response and cover it in `EngineTransportTest`; verify with the focused test.

## 3. Dashboard bot health and safe resume

- [x] 3.1 Add `App\Domain\Bot\BotHealth` to compute triage rows from the owned, non-archived bot collection and heartbeat, including the `max(180, interval + 30)` threshold, `started_at` restart grace, paused-by-engine detection, non-blank error detection, `can_resume` only for engine-paused rows, and the engine boundary comparison; add a unit test for each issue, archived omission, healthy omission, unavailable/old heartbeats, and the threshold boundary; verify with the focused `BotHealthTest`.
- [x] 3.2 Pass `botHealth` from `DashboardController` and add it to `Dashboard.vue`'s five-second partial reload; extend `DashboardTest` for stale, engine-paused, error, healthy omission, archived omission, old-engine/no-start-time, heartbeat-unavailable, restart-grace, and the partial reload; verify with the focused `DashboardTest`.
- [x] 3.3 Update `StartBot` to record a `bot.started` activity when resuming a `paused` bot whose desired status is already running, while preserving the duplicate-idle behavior; add `BotActionsTest` coverage; verify with the focused `BotActionsTest`.
- [x] 3.4 Build the dashboard "Needs attention" panel with issue badges, last tick/success, reason, paper one-click resume via `bots.start`, live link to `bots.show`, no resume action for stale/error rows, a local resuming state that clears on the next poll, an unconfirmed-resume message, Inertia error display, and hiding the panel when no rows need attention; verify with `npm run build` and the focused `DashboardTest`.
- [x] 3.5 Document the triage panel, badges, engine-paused resume split, pending/error states, and bot-controls changes in `docs/dashboard.md`; verify with the focused `DashboardDocsTest`.

## 4. Global engine status bar

- [x] 4.1 Add `resources/js/Support/engineStatus.js` with display mapping and a testable poller helper covering up/down, live/REST/idle/off/unknown, live on/off/unknown, engine-down gating, partial status, 503 parsing, network failure, and the ten-second throttle; verify with `npm run test:js`.
- [x] 4.2 Add `EngineStatusBar.vue` that fetches `health.engine` immediately and every 10 seconds, uses the module-level throttle across navigation, handles 503 and network failures, gates feed/live when the heartbeat is unavailable, shows "Live orders blocked" when the gate is off, announces only state transitions, and clears its interval on unmount; mount it in `AuthenticatedLayout.vue`; verify with `npm run build` and `npm run test:js`.
- [x] 4.3 Document the status bar states, engine-down unknown/last-known behavior, and warning-only behavior in `docs/dashboard.md`; verify with the focused `DashboardDocsTest`.

## 5. Integration verification

- [x] 5.1 Run the full PHP suite with the documented Docker `-e` overrides, PHPStan, Pint, frontend build, JS tests, and `make -C engine test`; verify all pass.
- [x] 5.2 Confirm no new live-start path exists: the dashboard's live action only links to the existing confirmation form, and `LiveModeGuard` remains the only live gate; verify by inspecting the route/action and running the live-guard tests.
- [x] 5.3 Confirm `docs/dashboard.md`, `docs/engine.md`, and `docs/runtime.md` still contain every route, config key, and feature-test name required by their docs tests, and that no UI text claims bots are paused when the engine is down; verify with the focused docs tests and a read-through.

## Workflow follow-up

- Archive the change after review and deployment verification.
