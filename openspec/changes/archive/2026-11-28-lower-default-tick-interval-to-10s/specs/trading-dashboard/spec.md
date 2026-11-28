# Spec Delta

## MODIFIED Requirements

### Requirement: Account settings hub

The dashboard SHALL provide a Settings hub that groups account configuration. The hub SHALL link to exchange credentials and risk limits, SHALL read out the effective default tick interval for new bots, and every settings page SHALL require an authenticated user.

#### Scenario: Open the settings hub

- **WHEN** a signed-in user opens the Settings hub
- **THEN** links to exchange credentials and risk limits are present

#### Scenario: Open exchange credentials from settings

- **WHEN** a signed-in user selects exchange credentials in the Settings hub
- **THEN** the exchange credential list is shown

#### Scenario: Open risk limits from settings

- **WHEN** a signed-in user selects risk limits in the Settings hub
- **THEN** the account risk-limit form is shown

#### Scenario: Effective tick interval is read out

- **WHEN** a signed-in user opens the Settings hub
- **THEN** the page shows that new bots default to the configured tick interval, states that individual bots can override it, and offers no control to change the default

#### Scenario: Reject an unauthenticated settings request

- **WHEN** an unauthenticated visitor requests a settings page
- **THEN** the visitor is redirected to sign in

### Requirement: Bot editing

The dashboard SHALL allow editing idle, stopped, and running paper bots. Editable fields SHALL be limited to name, capital allocation, fee rate, tick interval, and strategy parameters. Running paper edits SHALL show a field diff and ladder/order preview before explicit confirmation; the server SHALL reject them without confirmation. Live running and paused bots SHALL NOT be editable. Identity fields SHALL be immutable, and protective exits SHALL remain separately editable.

#### Scenario: Edit an idle bot

- **WHEN** a user submits valid changes for an idle bot
- **THEN** the bot and its strategy configuration are updated and the change is recorded in the activity log

#### Scenario: Edit the tick interval

- **WHEN** an owner submits a whole-second tick interval between 1 and 3600 on an editable bot
- **THEN** the stored interval is updated and the field diff is recorded in the activity log; the interval change does not itself request a reconcile, a reconcile or strategy reset already pending for another reason still runs, and an overdue bot may still be stale-paused by the separate stale check

#### Scenario: Reject an invalid tick interval

- **WHEN** an owner submits a tick interval below 1 second, above 3600 seconds, or not an integer
- **THEN** the request is rejected and the stored interval is unchanged

#### Scenario: Preview an interval-only edit

- **WHEN** an owner changes only the tick interval on a running paper bot's edit page
- **THEN** the page shows the tick interval in the field-by-field diff with its before and after values

#### Scenario: Preview a running paper edit

- **WHEN** an owner changes fields on a running paper bot's edit page
- **THEN** the page shows a field-by-field diff with before and after values and the ladder and order preview, without persisting the change

#### Scenario: Edit a running paper bot after confirmation

- **WHEN** an owner submits valid changes for a running paper bot with explicit confirmation
- **THEN** the bot and its strategy configuration are updated, the field diff is recorded in the activity log, and a reconcile is requested when the change affects the strategy structure or capital allocation

#### Scenario: Reject a running paper edit without confirmation

- **WHEN** an owner submits changes for a running paper bot without explicit confirmation
- **THEN** the request is rejected and the bot is left unchanged

#### Scenario: Reject a running paper edit after a status change

- **WHEN** a bot becomes running paper between the edit page load and submission and the request has no confirmation
- **THEN** the request is rejected and the bot is left unchanged

#### Scenario: No-op running paper edit

- **WHEN** an owner submits a running paper edit with no changed fields
- **THEN** no update is persisted, no reconcile is requested, and no open orders are cancelled

#### Scenario: Running paper bot exposes an edit action

- **WHEN** an owner views a running paper bot's detail page
- **THEN** the page offers the edit action

#### Scenario: Reject editing a running bot

- **WHEN** a user attempts to edit a running live bot
- **THEN** the request is denied and the bot is left unchanged

#### Scenario: Reject editing a paused bot

- **WHEN** a user attempts to edit a paused bot
- **THEN** the request is denied and the bot is left unchanged

#### Scenario: Identity fields cannot change

- **WHEN** an update request includes a symbol, exchange, mode, or strategy key
- **THEN** those values are ignored or rejected and the stored identity is unchanged

#### Scenario: Exit rules are not bot-edit fields

- **WHEN** a user edits an idle bot's name or strategy parameters
- **THEN** the bot's protective exit rules are unchanged
