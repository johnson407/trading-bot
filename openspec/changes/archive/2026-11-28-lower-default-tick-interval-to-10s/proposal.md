# Proposal

## Why

The default tick interval is 60 seconds because the previous change raised it from one second before any per-bot control existed. Operators now want faster paper-fill and exit detection, and they have no way to see the effective default or tune an individual bot. Ten seconds keeps exchange load modest while roughly sextupling how often a bot reacts.

## What Changes

- **BREAKING** Lower the default tick interval from 60 to 10 seconds in `trading.tick.interval_seconds` / `TRADING_TICK_INTERVAL`, the Go engine's `TRADING_TICK_INTERVAL` default, both Compose files, and `.env.example`.
- Add a forward-only migration that sets the `bots.tick_interval_seconds` column default to 10 and rewrites rows still at exactly 60 to 10, so existing bots adopt the new cadence. The `down` migration restores the column default only.
- Make `tick_interval_seconds` editable on the bot edit page. The engine reads the stored value on every due-check, so a change applies on the next cadence with no restart or reconcile by the change itself; the existing stale check may still pause an already-overdue bot and cancel its orders. Running paper edits keep the existing confirmation; live running and paused bots stay non-editable.
- Add a read-only card to the Settings hub showing the effective default for new bots and stating that individual bots can override it.
- Update the configuration documentation and the durable `bot-runtime` and `trading-dashboard` specs.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: the default interval requirement changes from 60 to 10 seconds, a stored interval change is defined to apply on the next due-check, and lowering an interval still respects the separate stale check.
- `trading-dashboard`: the settings hub gains a read-only effective-default readout, and bot editing gains the tick interval as an editable field.

## Impact

- Configuration: `config/trading.php`, `engine/internal/config/config.go` (and its config test), `docker-compose.yml`, `docker-compose.prod.yml`, `.env.example`, and the `TRADING_TICK_INTERVAL` test override in `phpunit.xml`.
- Persistence: a new migration changing the `bots.tick_interval_seconds` default and rewriting rows at 60.
- Backend: `BotSummaryResource`, `UpdateBotRequest`, `PreviewBotRequest`, `BotController::update()`, `BotEditDiff`, `SettingsController`.
- Frontend: `resources/js/Pages/Bots/Edit.vue` (field, preview payload, watch list, confirmation copy), `resources/js/Pages/Settings/Index.vue`.
- Tests: `BotCreationTest` and the existing migration test move to 10; new coverage for the migration, preview diff, interval update/validation, settings readout, the engine default, and a stored-interval change plus its stale-check transition in the Go runtime.
- Docs: `docs/exchanges.md` (test-asserted), `docs/dashboard.md` (test-asserted), `docs/bot-parameters.md`, `docs/runtime.md`, `docs/engine.md`, `docs/deployment.md`, `README.md`, `engine/README.md`.
- Rollout: an existing `.env` that pins `TRADING_TICK_INTERVAL=60` keeps 60 for new bots until an operator changes it and restarts the `app` and `engine` services.
- Runtime effect: with a 10-second interval the engine's cached-quote freshness becomes the 15-second floor instead of 30 seconds, so more ticks may fall back to a REST snapshot. The health stale threshold remains 180 seconds, but lowering a long interval can make an already-old last tick stale and pause the bot. Live bots moved from 60 to 10 by the migration make private exchange calls at six times the previous cadence; the live gate, credential and balance checks, rate limiting, and the non-editable live running/paused states are unchanged.
