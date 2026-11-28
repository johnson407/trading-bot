# Spec Delta

## ADDED Requirements

### Requirement: Single active live bot per exchange credential

The system SHALL allow at most one active live bot per exchange credential. A bot is active when it is not archived and either status is running or paused or desired status is running. Creation and start SHALL check the rule inside the same transaction that locks the credential row, excluding the bot being started. Creation and start SHALL reject a live bot without a credential. A conflict SHALL surface as an operator-facing validation or action error, not a server error.

#### Scenario: Second live bot is rejected while one runs

- **WHEN** a user creates or starts a live bot and another non-archived live bot on the same credential is running
- **THEN** the request is rejected with an operator-facing error and no second bot is started

#### Scenario: In-flight start blocks a second start

- **WHEN** a live bot already has desired status running but the engine has not changed its status yet
- **THEN** a second start on the same credential is rejected

#### Scenario: Resuming the same paused bot is allowed

- **WHEN** a user starts a paused live bot and it is the only non-archived live bot on that credential
- **THEN** the start is allowed

#### Scenario: Stopped bot frees the credential

- **WHEN** the previous live bot on a credential is stopped or archived
- **THEN** another live bot on that credential may be created or started

#### Scenario: Paper bots are unaffected

- **WHEN** any number of paper bots exist on an exchange
- **THEN** the single-live-bot rule does not reject them

#### Scenario: Live bot without a credential is rejected

- **WHEN** a user creates a live bot without an exchange credential
- **THEN** creation is rejected with an operator-facing error

#### Scenario: Concurrent starts produce one winner

- **WHEN** two start requests for different live bots on the same credential run concurrently
- **THEN** the credential lock serializes them and exactly one succeeds

### Requirement: Manual liquidation on safety pauses

Every automatic safety pause SHALL cancel pending, open, and partially filled orders and SHALL NOT submit a market or liquidation order. The covered causes SHALL be daily loss, protective exit, exchange failure threshold, repeated tick failure, stale tick, live gate, reconciliation conflict, strategy stop, unsupported exchange, and kill switch. Pausing SHALL NOT change base or quote inventory; inventory changes only from exchange fills. The operator SHALL flatten inventory manually.

#### Scenario: Daily-loss pause cancels without liquidating

- **WHEN** a bot pauses because the daily-loss limit is reached
- **THEN** pending, open, and partially filled orders are canceled, no market or liquidation order is submitted, and inventory is unchanged

#### Scenario: Kill switch cancels without liquidating

- **WHEN** the kill switch pauses a live bot with resting orders
- **THEN** those orders are canceled, no market or liquidation order is submitted, and inventory is unchanged

#### Scenario: Protective exit cancels without liquidating

- **WHEN** a stop-loss, take-profit, or range exit pauses a bot
- **THEN** its orders are canceled, no market or liquidation order is submitted, and inventory is unchanged

#### Scenario: Every automatic pause cause follows the policy

- **WHEN** a cause from daily loss, protective exit, exchange failure threshold, repeated tick failure, stale tick, live gate, reconciliation conflict, strategy stop, unsupported exchange, or kill switch pauses a bot
- **THEN** the pause cancels pending, open, and partially filled orders and submits no market or liquidation order

#### Scenario: Partial fills are canceled and retained

- **WHEN** an order has partially filled before a safety pause
- **THEN** the remaining order is canceled, the filled quantity remains in inventory, and no compensating market order is submitted

#### Scenario: No automatic liquidation path exists

- **WHEN** any safety pause path runs against a recording exchange fake
- **THEN** the exchange records zero market or liquidation orders and the bot's base and quote inventory are unchanged

#### Scenario: Operator flattens manually

- **WHEN** a safety pause leaves live inventory open
- **THEN** the operator follows the runbook's manual flatten steps, verifies the exchange balance, and records the evidence
