# Spec Delta

## MODIFIED Requirements

### Requirement: Scheduled maintenance

The system SHALL run scheduled health checks and balance synchronization for running bots. Health checks SHALL detect bots whose last successful tick is older than the configured threshold. Balance synchronization SHALL refresh a live bot's recorded balances from the exchange unless the account is shared by multiple live bots. A shared-account skip SHALL be recorded at most once per bot per UTC day.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the configured threshold
- **THEN** a health-check event is recorded and the stale state is visible

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

- **WHEN** a live tick takes longer than 15 seconds but less than the configured stale threshold
- **THEN** the bot is not paused by the health check
