# Spec Delta

## MODIFIED Requirements

### Requirement: Account equity snapshots

The system SHALL capture account-level equity snapshots at the configured cadence for each user with at least one bot, including a user whose bots are all archived. Each snapshot SHALL record the capture time, total equity, quote value, base value, realized profit and loss, unrealized profit and loss, fees paid, and bot count. The bot count SHALL include every active and archived bot whose balances are included. A user SHALL have at most one snapshot per capture bucket.

#### Scenario: Capture a running account

- **WHEN** a capture bucket elapses for a user with bots
- **THEN** one snapshot records the combined values for that user's active and archived bots

#### Scenario: Capture an account whose bots are all archived

- **WHEN** a capture bucket elapses for a user whose every bot is archived
- **THEN** a snapshot is still recorded with those archived bots' balances

#### Scenario: Skip a user without bots

- **WHEN** a capture bucket elapses for a user with no active or archived bots
- **THEN** no snapshot is recorded for that user

#### Scenario: Collapse duplicate captures

- **WHEN** capture runs more than once within the same bucket
- **THEN** the user still has one snapshot for that bucket

#### Scenario: Isolate accounts

- **WHEN** two users have bots
- **THEN** each snapshot contains only the values of that snapshot's owner

### Requirement: Equity calculation

The total equity of a snapshot SHALL equal the sum of each included bot's quote inventory plus its base inventory valued at the current mark price. A bot without a positive mark price SHALL value base inventory at its average entry price, and a bot with neither SHALL contribute only its quote inventory. Archived bots SHALL remain included using their last stored mark price, falling back to average entry price.

#### Scenario: Value inventory at the mark

- **WHEN** a bot holds base inventory and has a positive mark price
- **THEN** its base value uses that mark price

#### Scenario: Fall back to average entry

- **WHEN** a bot holds base inventory but has no positive mark price
- **THEN** its base value uses the average entry price

#### Scenario: Count quote-only inventory

- **WHEN** a bot holds no base inventory
- **THEN** its equity contribution is its quote inventory

#### Scenario: Include archived inventory

- **WHEN** an archived bot still holds quote or base inventory
- **THEN** its quote value, base value, realized profit and loss, unrealized profit and loss, and fees remain in the account snapshot
