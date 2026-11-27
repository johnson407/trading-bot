# Spec Delta

## ADDED Requirements

### Requirement: Source-backed settings are derived server-side

When a bot is created from a completed backtest run, the server SHALL derive its exchange, symbol, strategy, capital allocation, fee rate, strategy configuration, and protective exits from the run's immutable snapshot before request validation and engine plan evaluation. Browser-submitted source-derived values SHALL NOT be trusted.

#### Scenario: Snapshot settings are mapped for every grid strategy
- **WHEN** a source-backed bot is created from a simple, trailing, martingale, or dynamic grid run
- **THEN** the bot receives that strategy's center price, level count, spacing, order quantity, and strategy-specific parameters from the snapshot

#### Scenario: Level count falls back to the snapshot key
- **WHEN** the snapshot contains `levels_per_side` but not `level_count`
- **THEN** the mapper uses `levels_per_side` as the level count

#### Scenario: Sweep overrides are preserved
- **WHEN** the source run is a sweep run
- **THEN** the bot receives the run's swept center price, spacing, and level values rather than the original bot values

#### Scenario: Protective exits are copied
- **WHEN** the snapshot contains protective exits
- **THEN** the created bot receives those stop-loss, take-profit, and range settings

#### Scenario: Absent protective exits use defaults
- **WHEN** the snapshot has no protective exit section
- **THEN** the bot uses the normal creation defaults and creation still succeeds

#### Scenario: Dynamic grid zero spacing is handled
- **WHEN** a dynamic-grid snapshot carries a zero or absent spacing value
- **THEN** the mapper does not submit a zero value that fails validation and instead uses a valid omission or positive value

#### Scenario: Tampered source settings are rejected
- **WHEN** a client submits source-derived settings that differ from the run snapshot
- **THEN** validation and creation use the snapshot values and the created bot matches the source

#### Scenario: Preview and store agree
- **WHEN** the source-backed form requests a preview and then submits the same plan
- **THEN** preview and store derive the same settings from the source snapshot

#### Scenario: Source run deleted while the form is open
- **WHEN** the source run is deleted after the form opens but before submission
- **THEN** the request is rejected and no bot is created

### Requirement: Source-backed creation is paper and idle

Source-backed creation SHALL force paper mode, create the bot idle, persist the source link atomically, use the user's current risk limits, and SHALL NOT publish a start command.

#### Scenario: Paper mode is enforced
- **WHEN** a user attempts to create a source-backed bot with live mode
- **THEN** the request is rejected and no bot is created

#### Scenario: Bot is created idle
- **WHEN** a source-backed bot is created successfully
- **THEN** it is in paper mode with idle status and no start command is published

#### Scenario: Risk limits are current
- **WHEN** a source-backed bot is created
- **THEN** its risk limits come from the user's current limits, not from the historical backtest snapshot

#### Scenario: Engine preview unavailable fails closed
- **WHEN** the engine cannot evaluate the source-backed plan
- **THEN** validation fails and no bot is created

#### Scenario: Missing strategy configuration blocks creation
- **WHEN** the source run snapshot has no usable strategy configuration
- **THEN** the request is rejected and no bot is created

#### Scenario: Source link is persisted atomically
- **WHEN** source-backed creation succeeds
- **THEN** the bot, inventory, strategy configuration, protective exits, source link, and `bot.created` activity are committed together
