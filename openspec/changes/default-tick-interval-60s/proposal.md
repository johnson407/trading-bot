# Proposal

## Why

Bots currently default to a one-second evaluation cadence even though `TRADING_TICK_INTERVAL` already exists and is unused. Thousands of unnecessary evaluations per bot per hour consume exchange rate-limit budget and make paper fills and daily-loss checks fire on a cadence the operator never chose. Johnson has authorized a 60-second default tick interval, with the stale-tick threshold raised so that a 60-second cadence is not mistaken for an unhealthy bot.

## What Changes

- **BREAKING (default behavior):** the default tick interval becomes **60 seconds**. `TRADING_TICK_INTERVAL` / `config('trading.tick.interval_seconds')` becomes the default for new bots; runtime dispatch continues to read each bot's stored `tick_interval_seconds`. The one-second minimum remains.
- CreateBot, the bot factory, and the database seeder use the configured default with a floor of one second instead of hardcoding `1`.
- A migration changes the `bots.tick_interval_seconds` column default to 60 and updates existing rows still at the old hardcoded default of 1 to 60.
- The stale-tick default becomes **180 seconds** (`TRADING_STALE_TICK_SECONDS`). Health checks use an effective window of `max(configured stale threshold, bot tick interval + health-check interval)` so a 60-second bot is not paused by routine scheduling jitter.
- `bots:dispatch` stays on `everySecond()` as a cheap due-check; health, order-sync, and balance-sync schedules are unchanged. The dashboard poll stays at five seconds.
- Documentation describes four distinct cadences: the one-second dispatcher due-check, the per-bot evaluation interval (default 60 seconds), the health/order/balance schedules, and the five-second UI poll. The capacity harness remains a ten-bot / one-second-interval stress target.
- Paper fills and daily-loss evaluation still occur only when a tick runs, so at 60 seconds a price that crosses and recrosses a limit between ticks may not fill, and a daily-loss breach may be acted on up to roughly one interval late.

Decisions for Johnson to confirm:

1. **Global default plus stored per-bot interval, no new UI.** `TRADING_TICK_INTERVAL` supplies the default for new bots; there is no create/edit field for the interval (the existing hidden column remains the runtime source).
2. **Migrate existing interval = 1 rows to 60.** Because no UI exposes the column and the demo seeder bot is at 1, migrating the old hardcoded default is recommended; reject this if existing bots must stay at one second.
3. **Stale default 180 seconds plus the per-bot floor** `max(stale, tick_interval_seconds + health.check_interval_seconds)`.
4. **Accept 60-second latency** for paper-fill detection and daily-loss action between ticks.

Out of scope: a per-bot interval UI; changing the page poll; changing the health, order-sync, or balance-sync schedules; changing the consecutive-failure threshold; kill-switch behavior (already immediate); editing a live `.env`; Docker Compose command changes; exchange/testnet calls; implementing the change in this stage.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `bot-runtime`: periodic tick execution gains a 60-second configurable default while keeping the one-second minimum and per-bot stored interval; scheduled maintenance gains an effective stale threshold that cannot undercut a bot's own interval plus the health-check interval.
- `exchange-connectivity`: paper fills are explicitly evaluated from the market last at tick time, so a cross-and-recross between ticks is not guaranteed to fill.

## Impact

- Affected code: `config/trading.php`, `.env.example`, `app/Actions/CreateBot.php`, `database/factories/BotFactory.php`, `database/seeders/DatabaseSeeder.php`, `app/Domain/Bot/BotHealthMonitor.php`, a new bots-table migration, and the bot-runtime / exchange-connectivity tests.
- Affected docs: `README.md`, `docs/runtime.md`, `docs/exchanges.md`.
- Unchanged: `routes/console.php` dispatch cadence, `DispatchBotTicks`, `RunBotTick`, `BotTickPipeline`, kill switch, consecutive-failure threshold, dashboard poll, Docker scheduler command.
- Operators with `TRADING_TICK_INTERVAL=1` or `TRADING_STALE_TICK_SECONDS=60` pinned in a live `.env` keep those values until they edit the file; apply updates only `.env.example` and documents the step.
