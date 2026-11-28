# Design

## Context

See `proposal.md` for motivation. Two facts shape the approach:

- `TRADING_TICK_INTERVAL` is read only when a bot is created, then copied into `bots.tick_interval_seconds`. The Go engine's due-check query reads that column continuously (`GREATEST(tick_interval_seconds, 1)`), so the stored value is the runtime source of truth.
- The Settings hub is a display-only page rendered by a controller with no props and no persistence layer. There is no settings table, admin role, or config-write path.

The default 60 currently appears in `config/trading.php`, `engine/internal/config/config.go` (which also derives cached-quote freshness as `max(15s, interval/2)`), `docker-compose.yml`, `docker-compose.prod.yml`, `.env.example`, and the `TRADING_TICK_INTERVAL` test override in `phpunit.xml`.

## Goals / Non-Goals

**Goals:**

- New bots default to 10 seconds and existing bots still at 60 adopt 10 seconds.
- The effective default is visible in Settings without adding configuration persistence.
- An individual bot's interval can be changed from the edit page and takes effect on the next due-check.
- No new order-cancellation, ladder-rebuild, or engine-notification path is required for an interval-only change; the existing stale check may still pause an overdue bot and cancel its orders.

**Non-Goals:**

- No editable global default, no settings table, no admin authorization.
- No cron-style schedule expression or sub-second intervals.
- No change to the stale-health threshold rule (`max(180, interval + 30)`), which stays at 180 seconds for a 10-second bot.
- No relaxation of the existing edit restrictions for live running or paused bots.

## Decisions

### Every default moves together

Change `config/trading.php`, the Go `parsePositiveInt("TRADING_TICK_INTERVAL", ...)` fallback, both Compose defaults, `.env.example`, and the `TRADING_TICK_INTERVAL` override in `phpunit.xml` to 10 in one change. Changing only the Laravel default would leave the engine computing quote freshness from 60, make deployments without an explicit env value inconsistent between services, and keep the test suite pinning the old value. The engine's derived quote freshness becomes the 15-second floor at a 10-second interval; that is accepted rather than separately tuned.

### Migration rewrites rows at exactly 60

The runtime reads the column, so changing the config default alone would leave every existing bot at 60. The migration sets the column default to 10 and updates rows where `tick_interval_seconds = 60`. The old value was only ever a default — no UI has exposed the column — so rows at 60 are treated as unset. `down` restores the column default to 60 and does not rewrite data, matching the precedent in `2026_11_21_000200_set_default_bot_tick_interval_to_60`.

*Alternative considered:* no data migration and rely on the new per-bot field. Rejected because the operator asked for existing bots to move to 10 and manual per-bot edits would be tedious and easy to miss.

### Settings is a read-only readout

`SettingsController::index()` passes `max(1, (int) config('trading.tick.interval_seconds'))` as a prop — the same floored value `CreateBot` stores — and `Settings/Index.vue` renders a card stating that new bots use that cadence and individual bots can override it. This keeps the hub consistent with its current display-only role and avoids inventing persistence and authorization.

*Alternatives considered:* a database-backed global default needs a settings table, a migration, and an answer to "who may change it"; it would also still not affect existing bots, and the engine's startup-only quote freshness would not follow the UI value. A per-user default duplicates a setting users can already set per bot.

### Per-bot edit uses a non-required bounded integer

`UpdateBotRequest` validates `tick_interval_seconds` as `sometimes|integer|min:1|max:3600`. `sometimes` keeps existing update payloads and tests valid and treats an omitted field as "keep current"; one second matches the engine floor; 3600 seconds is a generous upper bound that keeps the field meaningful.

`update()` adds the value to `$proposed` for diffing and to `$attributes` for persistence. A `BotEditDiff::FIELDS` entry (`tick_interval_seconds`, label "Tick interval", type `integer`, source `bot`) makes the change participate in the no-op check, produces a before/after diff, and lands in the `bot.updated` activity context. Because it is neither a strategy field nor capital, the interval change does not itself set `reconcile_pending` or `strategy_reset_pending` and does not itself publish `bot.config`. A reconcile or strategy reset already pending for another reason is still retried and published, preserving the existing `BotUpdateTest` behavior.

`PreviewBotRequest::proposedEdit()` includes the interval, falling back to the bot's stored value when the field is absent, and `PreviewBotRequest::rules()` accepts the same bounded integer so the preview rejects invalid values instead of rendering a diff the save would refuse. `Edit.vue` initializes the field from `props.bot.tick_interval_seconds` (exposed by `BotSummaryResource`), renders a numeric input with `min="1"` and `max="3600"`, shows `InputError`, and adds the value to `previewPayload()` and the preview `watch` list. The existing running-paper confirmation remains mandatory for every save, including interval-only changes; that is intentionally simpler than adding a second confirmation branch. Its banner and checkbox copy are adjusted so it no longer promises that every save cancels orders.

### The engine picks up the change by polling

The due-check query already re-reads `tick_interval_seconds` on each loop, so no command, restart, or cache invalidation is needed. A tick in progress completes; the next due-check compares against the new interval.

The separate stale check also re-reads the stored interval and computes `max(180s, interval + 30s)`. Lowering a long interval therefore shortens the allowed gap: a bot whose last successful tick is already older than the new threshold is paused as a stale tick and has its open orders cancelled. That is existing safety behavior, not a reconcile, and this change does not weaken it. Operators lowering a long interval should expect the bot to tick promptly; if it was already overdue, it pauses.

### The runtime requirement is replaced explicitly, not relabeled

OpenSpec requires a MODIFIED block to retain every scenario name from the current spec, and it provides no way to rename a scenario. Rather than keep a scenario named "Default 60-second interval" whose text states 10 seconds, the delta removes `Periodic tick execution` and adds `Tick scheduling and cadence` with the same scheduling guarantees and accurate scenario names. The behavior contract is unchanged apart from the new default, the stored-interval update rule, and the explicit stale-check behavior.

## Risks / Trade-offs

- **Six-fold tick rate increases exchange and database work for paper and live bots** → paper bots make no private calls. Live bots make private order and balance calls at the new cadence, including bots moved from 60 by the migration; the engine's rate limiter and the documented capacity envelope (ten bots at one-second intervals) cover 10 seconds. The live gate, credential and balance checks, kill switch, and the non-editable live running/paused states are unchanged. Verify with the engine order-sync and rate-limit tests plus a post-migration live-bot smoke check.
- **Lowering a long interval can stale-pause a bot** → the stale threshold re-reads the stored interval as `max(180s, interval + 30s)`. A bot already overdue under its old interval pauses as a stale tick and has its open orders cancelled; this is existing safety behavior, not a reconcile, and is documented rather than suppressed.
- **An existing `.env` that pins `TRADING_TICK_INTERVAL=60` overrides the new default** → Compose interpolates the pinned value, so new bots and the engine's quote freshness stay at 60 until an operator updates `.env` and restarts the `app` and `engine` services. The data migration still moves existing bots to 10, so bots and new defaults can temporarily disagree. The operator step is documented rather than automated.
- **More REST fallbacks for market data** → the quote freshness floor drops from 30 to 15 seconds at a 10-second interval; this is a small increase in public snapshot requests, and a failed snapshot still fails the tick rather than trading on stale data.
- **The migration moves a deliberately configured 60-second bot** → no UI has ever exposed the column, so a deliberate 60 could only come from direct SQL; the rewrite is accepted and documented.
- **A running live bot cannot have its interval raised in place** → live running and paused bots remain non-editable by policy. An operator who wants a different live cadence must stop the bot first; this is existing behavior, not introduced here.
- **The Settings readout can disagree with the engine if the two services get different env values** → both Compose services read the same `TRADING_TICK_INTERVAL`, and the defaults change together; the readout describes new-bot behavior, not engine internals.

## Migration Plan

1. Ship the config, engine, Compose, `.env.example`, and `phpunit.xml` test-override changes with the migration.
2. `migrate --force` runs on the `app` service; Horizon and the scheduler keep `RUN_MIGRATIONS=false`.
3. Operators of an existing deployment change a pinned `TRADING_TICK_INTERVAL=60` in `.env` to 10 and restart the `app` and `engine` services. Neither the migration nor the deploy edits `.env`; this step is documented in `docs/deployment.md`.
4. The engine restart refreshes its quote-freshness default; per-bot interval changes need no restart.
5. Rollback reverts code defaults and runs `migrate:rollback`, which restores the column default to 60 but leaves rewritten rows at 10, matching the forward-only precedent. Reverting the data would require an explicit one-off update.

## Open Questions

None.
