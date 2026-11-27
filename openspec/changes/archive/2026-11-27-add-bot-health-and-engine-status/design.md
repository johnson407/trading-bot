# Design

## Context

See `proposal.md` for motivation and `specs/` for the behavior contract. The current engine heartbeat is a single Redis key with `ts`, `revision`, and `running_bots`; `EngineHeartbeat::status()` already tolerates missing or malformed data and never throws. Bot runtime fields (`status`, `desired_status`, `last_tick_at`, `last_successful_tick_at`, `last_error`, `tick_interval_seconds`) already exist, and `DashboardController` already loads every owned bot for the P&L summary. The dashboard polls Inertia props every five seconds; the authenticated layout has no engine indicator.

Two constraints shape the approach:

- A missing heartbeat is warning-only. Nothing in this change may pause bots, cancel orders, or activate the kill-switch when the heartbeat is absent.
- Live resume must keep the existing exact `<bot name> on <exchange>` confirmation and `LiveModeGuard`. The dashboard must not add a shortcut around it.

## Goals / Non-Goals

**Goals:**

- Make stale, engine-paused, and error bots visible on the dashboard with a reason and a safe resume path.
- Put a compact engine/feed/live indicator on every authenticated page.
- Extend the heartbeat without breaking an old engine or an old Laravel during rollout.
- Keep stale detection consistent with the engine's restart grace.

**Non-Goals:**

- Auto-resuming paused bots, changing the live gate, changing the stale threshold, or changing risk limits.
- Issue items 3-6 (grid activity, backtest-to-bot, alert grouping/push, editing running bots).
- A full per-bot monitoring table on the dashboard; the bot list remains the full inventory.
- Persisting market-data degraded/recovered events, which the engine currently only logs.
- Changing `/health/engine` visibility or adding authentication to it.

## Decisions

### 1. Extend the existing heartbeat rather than add a second status source

The payload gains three optional fields:

```json
{
  "ts": "2026-11-26T12:00:00Z",
  "revision": 42,
  "running_bots": 3,
  "started_at": "2026-11-26T11:00:00Z",
  "live_trading": false,
  "market_data": {
    "configured": true,
    "mode": "websocket",
    "degraded": false,
    "reason": "",
    "symbols": 2,
    "last_message_at": "2026-11-26T11:59:58Z"
  }
}
```

`ts`, `revision`, and `running_bots` keep their meaning. The new fields are additive, so an old Laravel ignores them and an old engine leaves them absent. Laravel must treat absent fields as unknown, not as `false`/`off`. The boolean fields `live_trading`, `market_data.configured`, and `market_data.degraded` MUST be serialized even when false; only `last_message_at` is omitted when it has no value. Without that rule, the normal "live off" and "feed not configured" states would disappear from the payload and render as unknown.

Alternatives considered:

- **Separate Redis key or engine HTTP endpoint:** adds a second read path, a second freshness rule, and more rollout states for no benefit; the feed status is cheap to read every five seconds.
- **Derive feed/live state in Laravel:** `live_trading` could come from config, but Laravel cannot know the engine's feed transport or degradation, and two sources would eventually disagree.

### 2. Go heartbeat API takes a snapshot provider

Replace the running-count argument with a snapshot so `main.go` supplies all fields from one place:

```go
type MarketData struct {
    Configured    bool
    Mode          string
    Degraded      bool
    Reason        string
    Symbols       int
    LastMessageAt *time.Time
}

type Snapshot struct {
    RunningBots int
    LiveTrading bool
    StartedAt   time.Time
    MarketData  MarketData
}

func (w *Writer) Write(ctx context.Context, snapshot Snapshot) error
func (w *Writer) Run(ctx context.Context, interval time.Duration, provider func() Snapshot)
```

`Payload` mirrors `Snapshot` with JSON tags. `StartedAt` is captured once in `main.go` before the first write. `main.go` maps `marketFeed.Status()` into heartbeat primitives (mode string, `len(status.Symbols)`, last message time) and passes `cfg.MarketData.Configured()` explicitly. `marketdata.Status` has no `Configured` field, so it must not be inferred from `mode != idle`: a configured feed with no subscribed symbols is idle, not off. `main.go` also passes `cfg.LiveTrading` and the running-bot count. The heartbeat package does not import `marketdata`.

`CheckFresh` decodes only `ts` into a minimal struct and validates only that timestamp and the TTL. It must not unmarshal the full payload: a malformed optional `started_at` or `market_data` member must never make readiness fail or trigger an engine restart. Add a test with a valid `ts` plus malformed optional fields that still passes `CheckFresh`.

Remove `WritePlaceholder` and update its tests to call `Write` with a `Snapshot`; it has no production callers. Add wire assertions for `live_trading:false`, `configured:false`, and `degraded:false` so `omitempty` cannot silently erase those states.

### 3. Laravel parses new fields as best-effort optional data

`EngineHeartbeat::status()` keeps `available`, `checked_at`, `last_seen_at`, and `running_bots` unchanged and adds:

```php
started_at: ?string,
live_trading: ?bool,
market_data: ?array{
    configured: ?bool,
    mode: ?string,
    degraded: ?bool,
    reason: ?string,
    symbols: ?int,
    last_message_at: ?string,
}
```

Rules:

- `started_at` is parsed as UTC RFC 3339; an invalid value becomes `null`.
- `live_trading` is accepted only when it is a JSON boolean; otherwise `null`.
- `market_data` is `null` when the key is absent or not an object, which the UI renders as unknown.
- Inside a present `market_data`, `configured` and `degraded` are accepted only when they are JSON booleans; otherwise they become `null`. A missing or malformed `configured` must not become `false` ("feed off"), and a missing or malformed `degraded` must not become `false` ("feed live").
- `mode`, `reason`, `symbols`, and `last_message_at` are best-effort; malformed values become `null`.
- Availability still depends only on `ts` and the existing 5-second future skew and 10-second TTL.

The UI treats a null `configured` as unknown. If `configured` is true and `degraded` is null with websocket mode, it also shows unknown rather than claiming live. A REST mode still shows REST fallback.

`tests/Support/FakeEngineHeartbeat.php` gains public `$startedAt`, `$liveTrading`, and `$marketData` properties and includes them in `status()`, defaulting to `null` so existing tests keep their behavior.

### 4. Bot health is computed in one domain class from already-loaded data

Add `App\Domain\Bot\BotHealth` with a method that accepts the owned bot collection, the heartbeat status array, and a UTC now. It returns only non-archived bots that need attention, with this shape:

```php
[
    'id' => int,
    'name' => string,
    'mode' => 'paper'|'live',
    'status' => string,
    'desired_status' => string,
    'last_tick_at' => ?string,
    'last_successful_tick_at' => ?string,
    'last_error' => ?string,
    'issues' => ['stale'|'paused_by_engine'|'error'],
    'reason' => string,
    'can_resume' => bool,
]
```

Detection:

- **Paused by engine:** `status === paused && desired_status === running`. Reason is `last_error` or a generic pause message; `can_resume` is true.
- **Error:** `status === error`, or `status === running` with a non-blank `last_error`. Reason is `last_error` or a generic error message; `can_resume` is false.
- **Stale:** only when `status === running && desired_status === running`, the heartbeat is available, `started_at` is present, the engine has been up for at least the threshold, and `COALESCE(last_successful_tick_at, last_tick_at, created_at)` is older than the threshold. Reason is "No successful tick within the stale threshold." `can_resume` is false; the row links to the bot page.
- **Threshold:** `max(180, tick_interval_seconds + 30)` seconds, matching the engine's current `staleThreshold`. The 30 is the engine's hardcoded health-check allowance; the delta spec corrects the old "health-check interval" wording. Pin both the 180-second floor and the interval-plus-30 rule in tests.
- **Boundary:** use the same strict comparison as the engine (`lastSuccessful.Add(threshold).Before(now)`), so a tick exactly at the threshold is not stale. Add a boundary test.
- **Archived:** ignore `$bot->trashed()` and never set `can_resume` for an archived bot.
- **Healthy:** running normally, idle, stopped, or paused by the user (desired paused/stopped) is omitted.

Using `started_at` as the process-level restart gate prevents a false stale badge after an engine restart. A bot that begins running later through `applyDesired` needs its own grace, because `applyDesired` currently sets `status='running'` without refreshing `last_successful_tick_at`. Change that transition to mirror `applyStatus`: clear `last_tick_at`, set `last_successful_tick_at = now()`, reset `consecutive_failures`, and clear `last_error`. This is the only engine runtime behavior change; it does not change the threshold, pause policy, or risk limits. With it, the dashboard's `last_successful_tick_at` guard covers the same case as the engine's per-bot `firstScheduled` grace.

A bot can have multiple issues (for example, running with an error and overdue). The UI renders one row per bot with a badge per issue and a combined reason. Ordering is paused-by-engine first, then stale, then error, then most recent tick. Hide the section when there are no rows rather than showing an empty panel.

`DashboardController` passes the result as a new `botHealth` prop and adds it to the five-second partial reload. No extra bot query is needed.

### 5. Resume reuses the existing start action and closes the audit gap

Only engine-paused rows get a one-click resume. The dashboard's paper-bot action posts to the existing `bots.start` route. The live-bot action is a link to the bot detail page, where the existing confirmation form and `LiveModeGuard` remain authoritative. Stale and error rows are informational and link to the bot page; they do not post a start command. No new route is added.

`StartBot` currently records `bot.started` only when the desired status was not already running, so an engine-paused resume is silent. Change the condition to record when the desired status was not already running **or** the bot's runtime status is `paused`:

```php
$resumed = $wasDesiredRunning && $bot->status === BotStatus::Paused;
// ... publish ...
if (! $wasDesiredRunning || $resumed) {
    $this->activity->recordUser(
        'bot.started',
        $resumed ? 'Bot resumed' : 'Bot started',
        $bot,
        context: [
            'mode' => $bot->mode->value,
            'symbol' => $bot->symbol,
            ...($resumed ? ['resumed' => true] : []),
        ],
    );
}
```

This preserves the existing "starts an idle bot and records the event once" behavior: a second start while the bot is still idle is not a resume. The activity type stays in `ActivityRecorder::USER_TYPES`; no engine-owned type is written.

`BotController::start` already returns `back()->withErrors(['action' => ...])` or `['confirmation' => ...]`. The dashboard reads Inertia's shared `errors` prop and renders `errors.action` / `errors.confirmation` in the triage panel, so a failed resume is not silent. Add a dashboard test for the failure path.

The dashboard shows a local "Resuming…" state after the request. Clear it on the next poll regardless of outcome. If the bot is still paused, show "Resume requested; engine has not confirmed" with the last pause reason and allow a retry. Do not leave the row in "Resuming…" indefinitely when the engine is down or the command is delayed.

### 6. The status bar is a self-contained polling component

Add `resources/js/Components/EngineStatusBar.vue` and mount it in `AuthenticatedLayout.vue` between the navigation and `FlashMessage`. It fetches the existing `health.engine` route immediately and every 10 seconds, parses JSON even when the response is 503, and treats a network failure as "status unavailable". It clears its interval on unmount.

Put both the display mapping and the fetch/poll logic in `resources/js/Support/engineStatus.js` so they can be unit-tested with `node --test`:

- `describeEngineStatus(heartbeat)` maps heartbeat data to engine/feed/live labels.
- `createEngineStatusPoller({ fetcher, intervalMs, now, onStatus })` performs the immediate fetch, the interval refresh, 503 parsing, network-failure handling, and in-flight deduplication.
- A module-level `lastFetchedAt` / in-flight promise bounds requests to at most one per ten seconds per tab, even when Inertia navigation remounts the layout. This is why the requirement says "per tab, including across navigation" rather than "while mounted."

Display mapping:

| Heartbeat | Display |
|-----------|---------|
| `available: true` | Engine heartbeat fresh, last seen time (or "never" if null) |
| `available: false` | Engine unavailable, last seen time (or "never"), warning-only tooltip; feed/live are unknown or explicitly last-known |
| `market_data: null` | Feed unknown |
| `configured: null` | Feed unknown |
| `configured: false` | Feed off; ticks use REST snapshots |
| `mode: websocket`, `degraded: false` | Feed live |
| `mode: websocket`, `degraded: null` | Feed unknown |
| `mode: rest` or `degraded: true` | REST fallback with reason |
| `configured: true`, `mode: idle` | Feed idle (no symbols) |
| `live_trading: true` | Red `LIVE ON` |
| `live_trading: false` | `Live orders blocked` with a tooltip explaining the gate |
| `live_trading: null` | Live unknown |

When `available` is false, do not render "Feed live" or "LIVE ON" from stale fields; the bar must not claim a current state it cannot verify. The down state explicitly says the engine is not pausing bots automatically, matching the existing dashboard warning. The prominent dashboard warning stays; the bar is the global compact indicator, not a replacement for the warning. The dashboard warning and the bar can briefly disagree because the dashboard uses its five-second Inertia prop and the bar uses its own ten-second cache; both derive from the same heartbeat and the cache bounds the difference.

The bar uses `role="status"`. Apply `aria-live="polite"` only when the engine/feed/live state transitions, not on every poll, so the changing "last seen" text is not announced every ten seconds. The bar's "up" state means a fresh heartbeat, not full `/healthz` readiness; the copy/tooltip makes that distinction.

### 7. Docs and tests follow the existing executable-fixture pattern

- `docs/dashboard.md` describes the triage panel, the paper/live resume split, the pending/error states, the status bar, and the new heartbeat fields. Update its bot-controls section for engine-paused resume, and keep every route and feature-test name `DashboardDocsTest` asserts.
- `docs/engine.md`, `docs/runtime.md`, and `engine/README.md` describe the heartbeat payload and `/health/engine`; update them for the new fields, `CheckFresh` behavior, the false-boolean serialization, and the unknown-on-old-engine behavior. Also fix the stale "running_bots is zero until the tick loop lands" note in `docs/engine.md`.
- `tests/Feature/DashboardTest.php` covers stale, engine-paused, error, healthy omission, archived omission, old-engine/no-start-time, heartbeat-unavailable, restart-grace, the `botHealth` partial reload, and resume failure.
- `tests/Feature/EngineTransportTest.php` covers present, missing, malformed, and partial optional heartbeat fields.
- `tests/Feature/BotActionsTest.php` covers the engine-paused resume activity entry and the unchanged duplicate-idle behavior.
- Go heartbeat tests cover the new wire payload, false booleans, malformed optional fields in `CheckFresh`, and the configured-but-idle mapping. Go runtime tests cover the `applyDesired` running transition.
- The JS helper test covers every display state, including idle, unknown versus off, engine-down gating, 503 parsing, network failure, and the ten-second throttle. `npm run test:js` only runs `resources/js/Support/*.test.js`, so the poller and mapping must live there.

## Risks / Trade-offs

- **False stale badges** → Require an available heartbeat, a known `started_at`, engine uptime beyond the threshold, and the bot's own last-success timestamp; refresh `last_successful_tick_at` on the `applyDesired` running transition. If any input is unknown, show nothing rather than guessing.
- **Laravel and engine stale formulas drift** → Correct the `Scheduled maintenance` spec to `max(180s, interval + 30s)` and pin the floor and allowance in tests on both sides.
- **A malformed optional heartbeat field breaks readiness** → `CheckFresh` decodes only `ts`; optional fields are parsed separately and never affect availability.
- **Nested heartbeat fields default to false** → `configured` and `degraded` are nullable in Laravel; partial data renders unknown, not off/live.
- **Public `/health/engine` exposes more operational detail** → The payload contains no URLs, credentials, symbol names, or secrets, only mode/booleans/reason/counts. If that becomes sensitive, restrict the route separately; this change does not alter its visibility. Add `Cache-Control: no-store` to the response as a low-cost precaution.
- **A resume looks like an engine pause until the next tick** → Show the last pause reason plus a local "Resuming…" state, clear it on the next poll, and report an unconfirmed resume.
- **Status-bar polling adds requests on every authenticated page** → A module-level ten-second throttle bounds requests across navigation; the endpoint is a single Redis GET.
- **Engine restart can briefly read the old heartbeat** → The old `started_at` may be visible until the new process overwrites the key. The window is bounded by the five-second refresh and ten-second TTL; stale is still gated on the heartbeat and start time, so the failure mode is a brief delay, not a false pause.
- **Live-gate source mismatch** → The bar reflects the engine's `live_trading` value, which is authoritative for runtime orders; Laravel's `LiveModeGuard` uses `config('trading.live_enabled')`. Document that the bar shows the engine gate, and keep the existing bot-page gate explanations.
- **Old engine during rollout shows unknown feed/live and no stale badges** → Intentional: unknown is safer than a false claim. Deploying either side first is safe; the Go fields are additive and Laravel fields are optional.

## Migration Plan

No database migration. Deploy in either order:

1. Laravel + frontend: the status bar shows feed/live as unknown and the triage panel shows engine-paused/error rows; stale waits for `started_at`.
2. Go engine: the heartbeat gains `started_at`, `live_trading`, and `market_data`; stale detection and full status-bar states become active; the `applyDesired` grace fix ships with the engine.

Rollback is a code revert. Extra heartbeat fields are ignored by the previous Laravel, and the previous engine simply omits them. No persisted state or schema depends on the new fields.

## Open Questions

None. The remaining choices (exact badge copy, mobile wrapping, and whether to pause polling on a hidden tab) do not change the specs, approach, or task breakdown.
