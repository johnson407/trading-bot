# Spec Delta

## ADDED Requirements

### Requirement: Running paper configuration reconcile

When a running paper bot's structural strategy configuration or capital allocation changes, the system SHALL mark the bot for reconcile, cancel open orders, reset strategy state, and clear the mark before evaluating orders for the new configuration. Name-only, fee-only, and no-op edits SHALL NOT reconcile. This reconcile path SHALL NOT apply to live bots.

#### Scenario: Structural change cancels and resets

- **WHEN** a running paper bot's strategy configuration changes and the reconcile command is processed
- **THEN** the engine cancels the bot's open orders, resets strategy state, clears the pending mark, and the next tick evaluates the new configuration

#### Scenario: Capital-only change reconciles

- **WHEN** only a running paper bot's capital allocation changes
- **THEN** the bot is marked for reconcile and open orders are cancelled before the next ladder is placed

#### Scenario: Non-structural change does not reconcile

- **WHEN** only the bot name or fee rate changes
- **THEN** the engine does not cancel open orders or reset strategy state, and the next tick uses the new values

#### Scenario: No-op edit does not reconcile

- **WHEN** a running paper edit contains no changed fields
- **THEN** no reconcile is requested and no open orders are cancelled

#### Scenario: Live bots are not reconciled by this path

- **WHEN** a reconcile is requested for a live bot
- **THEN** the engine does not cancel orders or reset strategy state and records that the request was ignored

#### Scenario: Repeated reconcile is idempotent

- **WHEN** the command and periodic paths both attempt a reconcile after the pending mark was already cleared
- **THEN** the later attempt does not cancel orders or reset strategy state again

### Requirement: Pending reconcile recovery

While a reconcile is pending, the engine SHALL block new order placement for that bot. A command-delivery or cancellation failure SHALL leave the pending mark in place so the command or periodic reconcile retries. Starting a bot with a pending reconcile SHALL complete the reconcile before running.

#### Scenario: Command delivery failure retries

- **WHEN** the application cannot publish the reconcile command after marking the bot
- **THEN** the pending mark remains and the periodic reconcile path completes the cancellation and reset

#### Scenario: Cancellation failure blocks placement

- **WHEN** cancelling an open order fails during reconcile
- **THEN** the pending mark remains and no new orders are placed for the new configuration until a later cancellation succeeds

#### Scenario: Tick does not place orders while pending

- **WHEN** a tick runs for a running paper bot with a pending reconcile
- **THEN** the tick attempts the reconcile first and places no new orders if it cannot complete

#### Scenario: Start completes a pending reconcile

- **WHEN** a stopped paper bot with a pending reconcile is started
- **THEN** the engine completes the reconcile before setting the bot running, or refuses the start and records the failure

#### Scenario: Stop and start does not cancel the new ladder

- **WHEN** a running paper edit marks a reconcile and the bot is stopped and started before the command is consumed
- **THEN** the start completes the reconcile before running and does not later cancel the newly placed ladder
