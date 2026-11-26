# bot-runtime Specification

## Purpose

Defines how bots are scheduled and executed continuously, how work survives restarts and duplicate deliveries, and how operators can observe bot health and errors.

## Requirements

### Requirement: Periodic tick execution

A running bot SHALL be evaluated by the Go engine at its stored tick interval, with a minimum interval of one second. New bots SHALL default to `trading.tick.interval_seconds` (`TRADING_TICK_INTERVAL`), which defaults to 60 seconds. The engine MAY wake more often as a due-check; a bot SHALL only be evaluated when its own interval has elapsed. A bot SHALL NOT have more than one tick in progress at a time.

#### Scenario: Tick at the configured interval

- **WHEN** a bot is running with an explicit one-second interval
- **THEN** the engine starts a new evaluation approximately every second

#### Scenario: Default 60-second interval

- **WHEN** a bot is created without an explicit tick interval
- **THEN** it stores the configured default of 60 seconds and is evaluated approximately every 60 seconds

#### Scenario: Overlapping ticks are prevented

- **WHEN** a tick is still running when the next interval elapses
- **THEN** the next tick waits and the bot never processes two ticks concurrently

#### Scenario: Tick-gated evaluation

- **WHEN** a 60-second bot's market price crosses an order limit between ticks
- **THEN** paper-fill detection and daily-loss evaluation wait for the next tick

### Requirement: Queued and idempotent work

Tick evaluation, order placement, order synchronization, and backtest execution SHALL run in the engine. Re-delivery of the same command or the same placement intent SHALL NOT create duplicate orders, duplicate fills, or duplicate inventory changes.

#### Scenario: Duplicate job delivery

- **WHEN** the command stream delivers the same start, placement, or cancel command twice
- **THEN** the second delivery makes no change and no duplicate order exists

#### Scenario: Queue backlog

- **WHEN** engine work for a bot is delayed
- **THEN** the bot continues from its last persisted state without skipping inventory or order updates

### Requirement: Restart recovery

On engine start the system SHALL resume bots that were running and reload their persisted strategy state. For live bots the engine SHALL reconcile local open orders with the exchange before processing new ticks, and SHALL also reconcile those orders periodically while it is running. Paper bots SHALL reconcile locally and SHALL NOT make private exchange calls. The Laravel scheduler SHALL NOT be responsible for pre-dispatch reconciliation after cutover.

#### Scenario: Resume a running bot

- **WHEN** the engine process starts and a bot was running before shutdown
- **THEN** the bot resumes from persisted state and continues processing ticks

#### Scenario: Reconcile open orders

- **WHEN** a resumed live bot has locally open orders
- **THEN** the system confirms each order's status with the exchange, applies partial fills to inventory while leaving the remainder open, and updates or removes orders that are fully filled or canceled

#### Scenario: Reconcile before scheduling

- **WHEN** the engine container starts with running live bots
- **THEN** those bots' open orders are reconciled before new ticks are evaluated

#### Scenario: Periodic reconcile

- **WHEN** a live bot has been running and the order-sync interval elapses
- **THEN** the engine confirms open orders with the exchange again and updates local state to match

#### Scenario: Paper reconcile stays local

- **WHEN** a paper bot is resumed or reaches a sync interval
- **THEN** it reconciles from local state and no private exchange request is sent

#### Scenario: Partial fill is applied

- **WHEN** reconcile sees a live order as partially filled
- **THEN** inventory is updated for the filled quantity, the remainder stays open, and no invented fill is recorded

#### Scenario: Reconcile respects rate limits

- **WHEN** a live reconcile request is rejected as rate limited
- **THEN** the engine backs off and does not immediately retry that request

### Requirement: Reconciliation conflicts

If reconciliation cannot match local state to the exchange, the system SHALL pause the affected bot, avoid placing new orders, and record a conflict describing the unmatched orders or inventory. When more than one live bot shares an exchange account, the whole-wallet balance comparison SHALL be skipped because the wallet is not attributable to one bot.

#### Scenario: Unmatched order

- **WHEN** reconciliation finds a locally open order the exchange does not recognize
- **THEN** the bot is paused and the discrepancy is recorded

#### Scenario: Unmatched inventory

- **WHEN** reconciled balances differ from recorded inventory beyond the configured tolerance on a single-bot account
- **THEN** the bot is paused and the difference is recorded

#### Scenario: Shared-account balances

- **WHEN** the reconciled bot shares its exchange account with another live bot
- **THEN** the balance comparison is skipped and no inventory conflict is recorded

### Requirement: Scheduled maintenance

The engine SHALL run health checks, order sync, balance sync, and stale-backtest failure. Health checks SHALL pause a bot whose last successful tick is older than the effective stale threshold (the greater of the default 180 seconds and the bot interval plus the health-check interval). Balance sync SHALL refresh a live bot unless the account is shared, with a skip at most once per bot per UTC day. Laravel SHALL NOT schedule `bots:health` or `backtests:fail-stale` after cutover.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the effective stale threshold
- **THEN** the engine pauses the bot and a health-check event is visible

#### Scenario: Not stale within one interval

- **WHEN** a running bot with a 60-second interval last succeeded within its interval plus the health-check interval
- **THEN** the health check does not pause it

#### Scenario: Balance synchronization

- **WHEN** scheduled balance sync runs for a live bot on a single-bot account
- **THEN** its recorded quote and base balances are refreshed from the exchange

#### Scenario: Shared account is skipped

- **WHEN** scheduled balance sync runs for a live bot whose account is shared with another live bot
- **THEN** its inventory is unchanged and a `bot.balance_sync_skipped` activity records `reason: shared_account`

#### Scenario: Skip notice is not repeated

- **WHEN** the same shared-account bot is skipped again on the same UTC day
- **THEN** no additional skip activity is recorded

#### Scenario: Slow live tick is not stale

- **WHEN** a live tick takes longer than 15 seconds but less than the effective stale threshold
- **THEN** the bot is not paused by the health check

#### Scenario: Stale backtest is failed by the engine

- **WHEN** a backtest run's worker disappears or exceeds the documented timeout
- **THEN** the engine marks that run failed with a concise message

### Requirement: Execution capacity

The engine SHALL support at least ten concurrently running bots with one-second tick intervals on the documented local hardware profile without missing their schedule. Exchange sessions SHALL be reused within the engine process, and the engine SHALL NOT start a second tick for a bot while its interval guard is open.

#### Scenario: Ten concurrent bots

- **WHEN** ten bots are running with one-second intervals on the documented local environment
- **THEN** each bot completes its ticks without persistent schedule drift

#### Scenario: Duplicate dispatch suppressed

- **WHEN** a bot already has a tick in progress for its current interval
- **THEN** the engine does not start another tick for that bot

### Requirement: Observability

The system SHALL emit structured execution records for each bot tick, order operation, fill, cancellation, reconciliation, and error. Records SHALL include the bot identifier, operation, outcome, and correlation identifier, and SHALL exclude secrets.

#### Scenario: Trace a bot tick

- **WHEN** a bot tick completes
- **THEN** a structured record identifies the bot, the tick outcome, the duration, and any orders, fills, or grid changes produced

#### Scenario: Surface errors

- **WHEN** execution produces an error
- **THEN** the error is recorded with the bot identifier and is visible in the bot's activity log

#### Scenario: Records exclude secrets

- **WHEN** a tick, order, fill, cancellation, reconciliation, or error record is emitted
- **THEN** it contains no credential, API key, secret, signature, or internal token

### Requirement: Tick completion record

Every bot tick that starts SHALL emit exactly one completion record with its outcome, duration, and counts of orders placed and canceled, fills, and grid levels created, matched, or replaced. The outcome SHALL distinguish `completed`, `paused`, `failed`, and `panicked`.

#### Scenario: Successful tick is visible

- **WHEN** a tick completes successfully without placing orders or recording fills
- **THEN** a completion record is still emitted with zero counts and its duration, so an absent record means no tick ran

#### Scenario: Paused tick is distinct from success

- **WHEN** a tick pauses the bot for a stale, risk, exit, live-gate, or strategy reason
- **THEN** its completion record has outcome `paused` rather than `completed`

#### Scenario: Failed tick is distinct from success

- **WHEN** a tick fails before the consecutive-failure threshold, or the failure handler itself returns without an error
- **THEN** its completion record has outcome `failed` and includes the failure type

#### Scenario: Panicked tick is recorded

- **WHEN** a tick panics
- **THEN** its completion record has outcome `panicked` before the panic is re-raised for the scheduler's existing panic handling

#### Scenario: One completion record per tick

- **WHEN** a tick reaches any completion, pause, failure, or panic path
- **THEN** exactly one completion record is emitted for that tick

#### Scenario: Grid churn is measurable

- **WHEN** a tick replaces a grid
- **THEN** its record includes the number of levels created and canceled orders, so ladder replacement can be measured without querying the database

### Requirement: Tick record correlation

The completion record and every order, fill, cancellation, or reconciliation record produced by a tick SHALL share the tick's correlation identifier.

#### Scenario: Correlation joins a tick's records

- **WHEN** a tick places, cancels, fills, or reconciles an order
- **THEN** that record and the tick completion record share the tick's correlation identifier

### Requirement: Go trading engine process

A dedicated Go engine process SHALL evaluate running bots, consume market data, place and sync paper and live orders, enforce runtime risk, reconcile on start and periodically, and run backtests. After cutover Laravel SHALL NOT evaluate ticks, place or sync orders, or replay backtests. Laravel MAY cancel open live orders only during a user-triggered kill-switch or stop when the heartbeat is missing or expired or command publish fails. v1 has one active command consumer.

#### Scenario: Engine evaluates a running bot

- **WHEN** a bot is running and its tick interval has elapsed
- **THEN** the Go engine performs the evaluation and persists the result without a Laravel tick job

#### Scenario: Laravel does not place trading orders

- **WHEN** the engine cutover is complete
- **THEN** Laravel HTTP workers and Horizon do not place, cancel, or synchronize trading orders on the exchange, except for the user-triggered live kill-switch or stop emergency cancel when the engine is unavailable or command publish fails

#### Scenario: Laravel emergency cancel on live kill-switch or stop

- **WHEN** a user activates the kill-switch or stops a live bot and the engine heartbeat is missing or expired, or command publish fails
- **THEN** Laravel cancels the affected live bots' open orders in the same action via `CancelBotOrders`

### Requirement: Engine heartbeat

The engine SHALL refresh a Redis heartbeat key at least every five seconds with a time-to-live of ten seconds, including a UTC timestamp and a count of running bots, and SHALL exclude secrets. Laravel health and the dashboard SHALL treat a missing or expired heartbeat as an outage and show a warning only. A missing or expired heartbeat SHALL NOT automatically pause bots, cancel orders, or activate the kill-switch.

#### Scenario: Heartbeat is visible while the engine is up

- **WHEN** the engine is running
- **THEN** Laravel can read a non-expired heartbeat and show the engine as available

#### Scenario: Expired heartbeat is an outage

- **WHEN** the heartbeat key is missing or expired
- **THEN** the dashboard or health view shows the engine as unavailable

#### Scenario: Expired heartbeat does not pause or cancel

- **WHEN** the engine heartbeat is missing or expired and the user has not activated the kill-switch or stop
- **THEN** Laravel does not pause bots, cancel orders, or activate the kill-switch, and only shows the engine as unavailable

### Requirement: Command and event streams

User start, pause, stop, cancel, kill-switch, and backtest requests SHALL be published on versioned stream `engine.commands` with an idempotency key. Consumption SHALL be at-least-once: `XACK` after durable effects, pending entries auto-claimed on start, and poison messages dead-lettered after limited retries. Commands for the same bot SHALL be applied in stream order. Unsupported schema versions SHALL not execute. Event payloads SHALL NOT include credentials, `APP_KEY`, or signatures.

#### Scenario: Command is applied once

- **WHEN** the same command identifier is delivered twice
- **THEN** the engine performs the action at most once

#### Scenario: Unsupported schema version is rejected

- **WHEN** a command arrives with a schema version the engine does not support
- **THEN** the engine does not execute it and records a failure event without secrets

#### Scenario: Start is issued from the dashboard

- **WHEN** a user starts a bot
- **THEN** Laravel records the desired status in Postgres and publishes a `bot.start` command for the engine

#### Scenario: Pending entries are reclaimed

- **WHEN** the engine starts and a command is still pending in the consumer group
- **THEN** the engine claims it and either applies it or, if its idempotency key is already completed, acknowledges it with no second effect

#### Scenario: Poison command is dead-lettered

- **WHEN** a command fails processing more than the documented retry limit
- **THEN** the engine writes it to the dead-letter stream, records a failure event without secrets, and acknowledges the original so the group is not blocked

#### Scenario: Per-bot commands are applied in order

- **WHEN** two commands for the same bot are published in sequence
- **THEN** the engine applies them in stream order and does not apply the later command first

### Requirement: Bot status ownership

Laravel SHALL own `desired_status` and SHALL write it on user start, pause, stop, and kill-switch actions. The engine SHALL own runtime `status` and `last_error`. After the ownership migration Laravel SHALL NOT write `status` or `last_error`. The engine SHALL NOT set `status` to running when `desired_status` is paused or stopped, and SHALL treat `desired_status` as authoritative user intent.

#### Scenario: Laravel writes only desired status

- **WHEN** a user starts, pauses, stops, or activates the kill-switch
- **THEN** Laravel updates `desired_status` and does not write `status` or `last_error`

#### Scenario: Engine writes runtime status and last_error

- **WHEN** the engine pauses a bot for stale, risk, or a live-gate failure
- **THEN** it updates `status` and `last_error` and does not change `desired_status`

#### Scenario: Engine does not resume against a user pause

- **WHEN** `desired_status` is paused or stopped
- **THEN** the engine does not set `status` to running and does not place new orders

### Requirement: Activity and settings ownership

After cutover, `activity_logs` SHALL be insert-only with the disjoint Laravel and engine type lists in the design. The engine SHALL NOT insert Laravel-listed types. Engine-owned `bots.settings` keys `mark_price`, `recent_prices`, and `daily_loss_baseline` SHALL be written only by the engine. Laravel may initialize `settings` to an empty object on create and SHALL merge later updates without overwriting those keys.

#### Scenario: Laravel bot update preserves engine settings

- **WHEN** Laravel updates a bot after the engine has stored mark or daily-loss keys in `settings`
- **THEN** those engine-owned keys are still present and unchanged

#### Scenario: Engine does not write Laravel activity types

- **WHEN** the engine records a runtime pause, fill, or cancel
- **THEN** it inserts only an engine-listed activity type and does not insert `bot.paused`, `bot.started`, or `bot.stopped`

### Requirement: Engine-owned live placement

Live order placement SHALL be performed by the Go engine when `TRADING_LIVE_ENABLED` is true. A closed gate SHALL pause or refuse a live bot and SHALL NOT run it as paper.

#### Scenario: Engine places live orders

- **WHEN** a live bot is running, `TRADING_LIVE_ENABLED` is true, and risk gates allow a limit order
- **THEN** the Go engine places the order on the selected exchange and records the result

#### Scenario: Closed gate does not fall back to paper

- **WHEN** a live bot is running or is started and `TRADING_LIVE_ENABLED` is false
- **THEN** the engine does not place live orders and does not convert the bot to paper mode

### Requirement: Archived bots are excluded from runtime execution

The engine SHALL exclude bots with a non-null archive timestamp from control-command application, tick due-checks, stale checks, reconciliation, and live-order synchronization. A start, pause, or stop command for an archived bot SHALL NOT change its status or publish a tick. An archived bot's own risk rules and protective exits SHALL NOT run.

#### Scenario: Stale start is ignored

- **WHEN** a `bot.start` command is delivered for a bot that was archived after the command was published
- **THEN** the engine does not change the bot's status and does not evaluate a tick for it

#### Scenario: Archived bots are not reconciled or checked for staleness

- **WHEN** the engine runs reconciliation or a stale-bot check
- **THEN** archived bots are not selected

#### Scenario: Restore does not start the bot

- **WHEN** an archived bot is restored
- **THEN** the engine does not resume it until a separate start command is issued

### Requirement: Archived balances remain in account risk

Account-level daily-loss evaluation SHALL include archived bots' realized profit and loss and inventory, because archiving does not remove account exposure.

#### Scenario: Archived balances count toward account daily loss

- **WHEN** an active bot's tick evaluates the owner's daily loss
- **THEN** archived bots' realized profit and loss and inventory are included in the account total even though the archived bots are not ticked
