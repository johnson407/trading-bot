# backtest-to-bot Specification

## Purpose

Lets a user turn a completed backtest or sweep run into a paper bot without re-entering its settings, preserve provenance, and reach the paper-versus-backtest comparison defined by the dashboard capability.

## Requirements

### Requirement: Source backtest link

A paper bot MAY record the completed backtest run it was created from. The link SHALL be optional, owner-scoped, and read-only after creation. Deleting the source run SHALL clear the link without deleting or changing the bot. A bot whose source run was deleted SHALL be indistinguishable from a bot created without a source, and no comparison SHALL be shown for it.

#### Scenario: Link is recorded at creation
- **WHEN** a paper bot is created from a completed backtest run
- **THEN** the bot records that run as its source and the run remains unchanged

#### Scenario: Bot without a source has no comparison
- **WHEN** a bot was created without a source backtest
- **THEN** the bot has no source link and no backtest comparison is shown

#### Scenario: Deleting the source run preserves the bot
- **WHEN** the owner deletes a backtest run that is the source of a bot
- **THEN** the bot's source link is cleared, the bot and its orders, fills, and P&L remain intact, and the comparison disappears

#### Scenario: Source link cannot be edited
- **WHEN** the owner edits an idle or stopped bot
- **THEN** the source backtest link cannot be changed

### Requirement: Create paper bot from a completed backtest run

The owner of a completed backtest run with a usable snapshot, an available strategy, an allowed exchange, and a valid symbol SHALL be able to open the existing bot creation form prefilled from that run. The action SHALL be unavailable for runs that are pending, running, or failed, whose strategy or snapshot is unusable, whose exchange is no longer allowed, or that belong to another user. Another user's run SHALL be resolved as not found.

#### Scenario: Completed usable run exposes the action
- **WHEN** the owner views a completed backtest run with a usable snapshot and an available strategy
- **THEN** the page offers a create-paper-bot action that opens the prefilled creation form

#### Scenario: Incomplete or failed run has no action
- **WHEN** a backtest run is pending, running, or failed
- **THEN** the page does not offer the create-paper-bot action

#### Scenario: Unusable source is rejected
- **WHEN** the run's strategy is unavailable, its snapshot lacks usable configuration, its exchange is no longer allowed, or its symbol is invalid
- **THEN** the action is unavailable or the request is rejected before a bot is created

#### Scenario: Another user's run is not found
- **WHEN** a user requests creation from a backtest run they do not own
- **THEN** the request is rejected as not found and no bot is created

#### Scenario: Source run deleted while the form is open
- **WHEN** the source run is deleted after the creation form opens but before submission
- **THEN** the submission is rejected and no bot is created

#### Scenario: Source bot deletion does not block creation
- **WHEN** the backtest run's original source bot has been deleted but the run snapshot is intact
- **THEN** the owner can still create a paper bot from the run

#### Scenario: Multiple bots may use one source run
- **WHEN** the owner creates more than one paper bot from the same completed run
- **THEN** each bot records the same source run and the bots remain independent

### Requirement: Create from a sweep run

Each completed run row in a sweep detail page SHALL expose the create-paper-bot action. The created bot SHALL link to that run and use the run's swept center price, spacing, and level overrides.

#### Scenario: Completed sweep row exposes the action
- **WHEN** the owner views a completed run row in a sweep
- **THEN** the row offers a create-paper-bot action

#### Scenario: Incomplete sweep row has no action
- **WHEN** a sweep run is pending, running, or failed
- **THEN** its row does not offer the create-paper-bot action

#### Scenario: Sweep overrides are used
- **WHEN** a bot is created from a completed sweep run
- **THEN** the bot uses that run's swept center price, spacing, and level values

### Requirement: Ownership and authorization

Only the owner of a backtest run SHALL create a bot from it. The source run SHALL be resolved through an owner-scoped query so another user's run is not revealed. Only the bot owner SHALL view its comparison; another user's bot request SHALL be rejected by the existing bot authorization policy. Unauthorized creation SHALL create no bot and SHALL not reveal source details.

#### Scenario: Another user cannot create from the run
- **WHEN** a user submits a source-backed creation request for another user's run
- **THEN** the request is rejected as not found and no bot is created

#### Scenario: Another user cannot view the comparison
- **WHEN** a user requests a bot they do not own
- **THEN** the request is rejected by the existing bot authorization policy and the comparison is not revealed
