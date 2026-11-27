# Spec Delta

## ADDED Requirements

### Requirement: Engine status snapshot

The engine SHALL include in its heartbeat a UTC engine start time, whether live trading is enabled, and a market-data snapshot with configured state, mode, degraded state, reason, symbol count, and last-message time. These fields SHALL exclude secrets, SHALL serialize boolean false values, and SHALL be optional to consumers so an older engine's heartbeat remains valid.

#### Scenario: Snapshot is present while the engine is healthy

- **WHEN** the engine is running with the market-data feed configured and connected
- **THEN** the heartbeat reports the engine start time, the live-trading gate state, and a websocket, non-degraded market-data snapshot with the configured state true

#### Scenario: Feed is not configured

- **WHEN** the engine has no market-data endpoints configured
- **THEN** the heartbeat reports an idle feed with the configured state false

#### Scenario: Feed is configured but idle

- **WHEN** the engine has market-data endpoints configured but no symbols subscribed
- **THEN** the heartbeat reports the configured state true, idle mode, and no degradation

#### Scenario: Feed has fallen back to REST

- **WHEN** the market-data feed is degraded or using REST fallback
- **THEN** the heartbeat reports the REST mode, the degraded state, and a reason

#### Scenario: Live trading is enabled

- **WHEN** the engine's live-trading gate is enabled
- **THEN** the heartbeat reports live trading true

#### Scenario: Live trading is disabled

- **WHEN** the engine's live-trading gate is disabled
- **THEN** the heartbeat reports live trading false rather than omitting the field

#### Scenario: Older engine remains compatible

- **WHEN** a consumer reads a heartbeat written by an older engine without the status fields
- **THEN** it treats the missing status as unknown and still considers the heartbeat valid

#### Scenario: Malformed optional status does not invalidate the heartbeat

- **WHEN** a heartbeat has a valid timestamp but a malformed start time or market-data object
- **THEN** the consumer still considers the heartbeat available and treats the malformed status as unknown

## MODIFIED Requirements

### Requirement: Scheduled maintenance

The engine SHALL run health checks, order sync, balance sync, and stale-backtest failure. Health checks SHALL pause a bot whose last successful tick is older than the effective stale threshold (the greater of 180 seconds and the bot interval plus 30 seconds). Balance sync SHALL refresh a live bot unless the account is shared, with a skip at most once per bot per UTC day. Laravel SHALL NOT schedule `bots:health` or `backtests:fail-stale` after cutover.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the effective stale threshold
- **THEN** the engine pauses the bot and a health-check event is visible

#### Scenario: Not stale within one interval

- **WHEN** a running bot with a 60-second interval last succeeded within its effective threshold
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

#### Scenario: Threshold uses the 30-second allowance

- **WHEN** a running bot has a 300-second interval and last succeeded 320 seconds ago
- **THEN** the health check does not pause it because the effective threshold is 330 seconds
