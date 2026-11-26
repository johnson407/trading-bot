# Spec Delta

## ADDED Requirements

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
