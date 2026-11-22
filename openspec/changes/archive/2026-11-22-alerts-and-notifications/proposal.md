# Proposal

## Why

The bot runtime already detects the incidents that matter — daily-loss pauses, repeated exchange failures, stale tick loops, reconciliation conflicts, live-gate pauses, and kill-switch activation — but it only writes rows to `risk_events` and `activity_logs`. Nobody is notified. An unattended bot can pause itself and the user finds out only by opening the dashboard.

Backtesting is now shipped, so the next milestone is operating safely rather than proving strategies. A guardrail that fires silently is not a guardrail a user can trust with real funds.

## What Changes

- Introduce an owner-scoped alert delivery path fed by the existing risk and activity recorders; no trading behavior changes.
- Notify on a curated set of operational incidents instead of every log row:
  - daily-loss limit pause (`risk.daily_loss_limit`)
  - consecutive exchange failure pause (`exchange.failure_threshold`)
  - stale tick loop pause (`risk.stale_bot` / `bot.stale`)
  - reconciliation conflict pause (`risk.reconciliation_conflict`)
  - live-trading gate pause (`risk.live_trading_disabled`)
  - kill-switch activation (`kill_switch.activated`)
  - order-cancellation failures raised while pausing (`risk.*_cancel_failed`, including `risk.failure_cancel_failed`)
- Deliver each alert in-app and, for critical incidents, by email. Delivery is queued, runs after the trade transaction commits, and never blocks or fails a tick.
- Deduplicate repeated alerts for the same user, bot, and incident type inside a configurable window so a retrying loop cannot spam the user.
- Add an authenticated alert inbox that lists alerts newest first, shows unread state, supports marking one or all as read, and filters by bot. Link it from the primary navigation.
- Preserve the existing source records: `risk_events` and `activity_logs` remain the audit trail; alerts reference their source rather than replacing it.

## Capabilities

### New Capabilities

- `alerts`: owner-scoped delivery, deduplication, channel selection, and the in-app alert inbox for trading incidents.

### Modified Capabilities

None. The existing `risk-controls` and `trading-dashboard` requirements stay unchanged; alerting observes their behavior rather than replacing it.

## Impact

- **Application**: `RiskEventRecorder`, `ActivityRecorder`, and the pause paths in `DailyLossMonitor`, `ConsecutiveFailureTracker`, `BotHealthMonitor`, and `ActivateKillSwitch` gain an alert hook. A new alert recorder, mailable, queued job, controller, and Inertia page are added.
- **Data**: a dedicated `alerts` table with an owner/bot/type dedupe index. No changes to trading tables.
- **Configuration**: alert window/retention and channel defaults in `config/`; mail transport remains deployment-configured (`MAIL_MAILER=log` by default).
- **Queue**: alerts use the existing Redis/Horizon queue. Alert storage and delivery failures are logged and never propagate into the tick or action that triggered them.
- **Tests**: `Queue::fake()` and `Mail::fake()` coverage for dispatch, dedupe, after-commit isolation, ownership, and inbox read state.
- **No breaking changes** to bots, orders, fills, risk limits, or backtests.
