# Spec Delta

## Purpose

Captures and serves account-level equity history so users can evaluate performance and drawdown over time.

## ADDED Requirements

### Requirement: Account equity snapshots

The system SHALL capture account-level equity snapshots at the configured cadence for each user with at least one bot. Each snapshot SHALL record the capture time, total equity, quote value, base value, realized profit and loss, unrealized profit and loss, fees paid, and bot count. A user SHALL have at most one snapshot per capture bucket.

#### Scenario: Capture a running account

- **WHEN** a capture bucket elapses for a user with bots
- **THEN** one snapshot records the combined values for that user's bots

#### Scenario: Skip a user without bots

- **WHEN** a capture bucket elapses for a user with no bots
- **THEN** no snapshot is recorded for that user

#### Scenario: Collapse duplicate captures

- **WHEN** capture runs more than once within the same bucket
- **THEN** the user still has one snapshot for that bucket

#### Scenario: Isolate accounts

- **WHEN** two users have bots
- **THEN** each snapshot contains only the values of that snapshot's owner

### Requirement: Equity calculation

The total equity of a snapshot SHALL equal the sum of each included bot's quote inventory plus its base inventory valued at the current mark price. A bot without a positive mark price SHALL value base inventory at its average entry price, and a bot with neither SHALL contribute only its quote inventory.

#### Scenario: Value inventory at the mark

- **WHEN** a bot holds base inventory and has a positive mark price
- **THEN** its base value uses that mark price

#### Scenario: Fall back to average entry

- **WHEN** a bot holds base inventory but has no positive mark price
- **THEN** its base value uses the average entry price

#### Scenario: Count quote-only inventory

- **WHEN** a bot holds no base inventory
- **THEN** its equity contribution is its quote inventory

### Requirement: Historical retention

Snapshots SHALL be retained for the configured retention period, defaulting to 90 days. Retention SHALL delete only expired snapshots and SHALL leave fills, inventory ledger entries, risk events, and activity logs untouched.

#### Scenario: Prune expired snapshots

- **WHEN** retention runs and snapshots are older than the configured period
- **THEN** those snapshots are deleted

#### Scenario: Keep recent snapshots

- **WHEN** retention runs and snapshots are within the configured period
- **THEN** those snapshots remain

#### Scenario: Preserve source history

- **WHEN** retention deletes expired snapshots
- **THEN** fills, inventory ledger entries, risk events, and activity logs remain unchanged

### Requirement: Period performance

For a requested range, the system SHALL return the snapshots in chronological order and calculate period return, peak equity, and maximum drawdown. With fewer than two snapshots in the range, it SHALL return the available points without fabricating performance metrics.

#### Scenario: Calculate range metrics

- **WHEN** a range contains at least two snapshots
- **THEN** the response includes period return, peak equity, and maximum drawdown

#### Scenario: Handle a single point

- **WHEN** a range contains one snapshot
- **THEN** the point is returned and performance metrics are unavailable

#### Scenario: Handle an empty range

- **WHEN** a range contains no snapshots
- **THEN** the response reports that history is not yet available

### Requirement: Analytics ownership

Equity history SHALL be scoped to the authenticated owner. Capture and reads SHALL never include another user's bots or snapshots.

#### Scenario: Read only your own history

- **WHEN** a user requests equity history
- **THEN** only snapshots owned by that user are returned

#### Scenario: Capture each account separately

- **WHEN** capture runs for multiple users
- **THEN** each user's snapshot contains only that user's bots

### Requirement: History starts at capture

The system SHALL NOT backfill snapshots from fills or inventory history. Equity history SHALL begin at the first successful capture after deployment.

#### Scenario: Older activity does not create history

- **WHEN** fills exist from before the feature was deployed
- **THEN** no equity snapshot is created for those earlier times
