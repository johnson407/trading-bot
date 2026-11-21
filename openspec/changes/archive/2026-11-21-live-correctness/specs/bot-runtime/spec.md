## MODIFIED Requirements

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

The system SHALL run scheduled health checks and balance synchronization for running bots. Health checks SHALL detect bots whose last successful tick is older than the configured threshold. Balance synchronization SHALL refresh a live bot's recorded balances from the exchange unless the account is shared by multiple live bots.

#### Scenario: Stale bot detected

- **WHEN** a running bot has not completed a tick within the configured threshold
- **THEN** a health-check event is recorded and the stale state is visible

#### Scenario: Balance synchronization

- **WHEN** scheduled balance sync runs for a live bot on a single-bot account
- **THEN** its recorded quote and base balances are refreshed from the exchange

#### Scenario: Shared account is skipped

- **WHEN** scheduled balance sync runs for a live bot whose account is shared with another live bot
- **THEN** its inventory is unchanged and a `bot.balance_sync_skipped` activity records `reason: shared_account`
