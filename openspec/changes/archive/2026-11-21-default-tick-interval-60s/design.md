# Design

## Context

See `proposal.md` for motivation. Today the scheduler in `routes/console.php` runs `bots:dispatch` every second. `DispatchBotTicks` reads each running bot's `tick_interval_seconds`, floors it at one second, skips the bot when `last_tick_at` is newer than `now - interval`, and uses a per-bot cache guard before queueing `RunBotTick`. `RunBotTick` is unique for 10 seconds with a 30-second overlap expiry. `BotTickPipeline` records `last_tick_at` at the start of a tick, records `last_successful_tick_at` on success, evaluates daily loss, and detects paper fills from the market last at tick time.

`TRADING_TICK_INTERVAL` / `trading.tick.interval_seconds` already exists but is unused: CreateBot, `BotFactory`, `DatabaseSeeder`, and the create-bots migration all hardcode `1`, and dispatch reads only the per-bot column. The current stale default is 60 seconds (`trading.tick.stale_after_seconds`), while `BotHealthMonitor` applies one global cutoff to every running bot. A 60-second tick interval with a 60-second stale cutoff would pause healthy bots on ordinary jitter, because health runs only every 30 seconds.

## Goals / Non-Goals

**Goals:**

- Make 60 seconds the out-of-the-box interval for new bots and seeded bots.
- Keep the stored per-bot interval as the runtime source and keep the one-second minimum.
- Make stale detection tolerate any bot interval, not just the new default, without weakening detection of genuinely stuck bots.
- Migrate existing rows that still carry the old hardcoded default.

**Non-Goals:**

- No per-bot interval field in the create/edit UI, and no new env key.
- No change to the dispatcher schedule, Horizon settings, job uniqueness, overlap guards, health/order/balance schedules, or the five-second UI poll.
- No change to the consecutive-failure threshold or kill-switch behavior.
- No live `.env` mutation, Docker Compose change, or exchange/testnet call.

## Decisions

### Global default plus stored per-bot interval

`config('trading.tick.interval_seconds')` becomes the default written by CreateBot, `BotFactory`, and `DatabaseSeeder`, floored with `max(1, ...)`. Runtime dispatch keeps reading `bots.tick_interval_seconds`. A per-bot UI was rejected because it expands this change into controller, validation, and Vue work, and the operator authorized only a changed default. A new env key was rejected because `TRADING_TICK_INTERVAL` already exists and is the natural source.

### Keep `bots:dispatch` on `everySecond()`

The dispatcher is a cheap due-check; the bot's interval decides whether work is actually queued. Moving the schedule to every minute would add up to a minute of jitter and make sub-minute explicit intervals awkward. Health (30s), order sync (10s), and balance sync (60s) remain unchanged. `uniqueFor` and `WithoutOverlapping` remain overlap guards, not cadence controls.

### Stale threshold: default 180 seconds with a per-bot floor

Change `TRADING_STALE_TICK_SECONDS` / `trading.tick.stale_after_seconds` to default to 180. For each running bot, `BotHealthMonitor` uses:

`effective = max(stale_after_seconds, tick_interval_seconds + trading.health.check_interval_seconds)`

With defaults this is `max(180, 60 + 30) = 180` for a 60-second bot, and `max(180, 300 + 30) = 330` for a future 300-second bot. The current single global cutoff query becomes a per-bot comparison, and the code fallback changes from 15 to 180 so it matches the config default. Three minutes tolerates a late tick plus one missed health cycle while still pausing a bot that has actually stopped. The alternative — leaving stale at 60 — guarantees healthy pauses; lowering the default interval instead — contradicts the requested behavior.

### Migrate existing interval = 1 rows

A migration sets the `bots.tick_interval_seconds` column default to 60 and updates rows where the value is still 1 to 60. There is no UI to adjust the column, and the demo seeder bot is at 1, so leaving rows behind would silently contradict the new default. The trade-off is that a bot intentionally set to one second would also move to 60; with no UI and no override request, that is accepted. The `down` migration restores the column default to 1 but does not rewrite data, because new 60-second rows are legitimate.

### Latency consequences are documented, not redesigned

Paper fills, daily-loss evaluation, and consecutive-failure accounting are tick-gated. At 60 seconds a paper limit can be crossed and recrossed between ticks without a fill, and a daily-loss breach or third consecutive failure can be observed up to about one interval later. The kill switch already takes effect immediately and is untouched. Shortening these paths (for example, a separate fill poll) is out of scope.

### Env pins are operator work

Laravel reads the live `.env` first. Apply updates `.env.example` and documentation only; an operator whose `.env` still pins `TRADING_TICK_INTERVAL=1` or `TRADING_STALE_TICK_SECONDS=60` must change those values themselves. Apply must not read or edit `.env`.

## Risks / Trade-offs

- **Existing one-second bots are slowed without warning** → Confirm the data migration decision before apply, and document the change with a rollback that restores the column default.
- **A stuck 60-second bot is paused after 180 seconds rather than 60** → Consecutive failures and stale activity events still surface it; kill switch remains immediate.
- **Missed paper fills and later daily-loss action** → Accepted by the proposal and stated in the `exchange-connectivity` and `bot-runtime` deltas.
- **Operators keep old env pins** → Document the two keys in `.env.example` comments and the runtime docs, and call out the operator step in `tasks.md`.
- **The health monitor becomes per-bot instead of one query** → Running-bot volume is small (capacity target is ten), so filtering in PHP is acceptable and avoids a complex SQL expression.

## Migration Plan

1. Update config and `.env.example` defaults.
2. Ship the migration and code defaults together so new and existing bots agree.
3. Deploy, then update any live `.env` pins and restart the scheduler/queue workers.
4. Roll back by restoring the previous config values and column default; data changed from 1 to 60 is not automatically reverted.
