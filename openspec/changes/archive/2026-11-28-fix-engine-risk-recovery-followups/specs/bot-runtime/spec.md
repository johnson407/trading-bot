# Spec Delta

## ADDED Requirements

### Requirement: Pause provenance refresh during engine bookkeeping

When the engine updates a paused bot's `updated_at` for cancellation retry, reconcile, or strategy-reset bookkeeping without changing the pause cause, it SHALL refresh `pause_reason_recorded_at` in the same update so the current cause remains eligible for auto-resume. Start, stop, and successful auto-resume SHALL clear both fields; the kill-switch SHALL continue to record the kill-switch cause and time.

#### Scenario: Cancellation retry keeps a paused cause current

- **WHEN** pausing a bot has to schedule an order-cancellation retry, or a later cleanup clears that retry
- **THEN** the same update refreshes the pause recording time and the stale-tick cause remains eligible

#### Scenario: Reconcile cleanup keeps a paused cause current

- **WHEN** the engine clears a pending reconcile or strategy reset for a paused bot
- **THEN** the same update refreshes the pause recording time and the stale-tick cause remains eligible

#### Scenario: Failed cleanup keeps a paused cause current

- **WHEN** a reconcile cancellation fails and the engine keeps the cancellation retry pending
- **THEN** the same update refreshes the pause recording time and the stale-tick cause remains eligible

#### Scenario: Running bots are unaffected

- **WHEN** engine bookkeeping updates a running bot's control flags
- **THEN** its null pause cause and recording time are not populated

### Requirement: Auto-resume waits for pending cleanup

Before auto-resuming stale-paused bots, the engine SHALL attempt pending cancellation-retry, reconcile, and strategy-reset cleanup for paused bots. The first recovery pass SHALL capture the stale-paused paper Binance bots present when the process starts, and later passes SHALL retry only that captured set. A bot whose cleanup has not completed SHALL NOT auto-resume on that pass, but an eligible bot with no pending cleanup SHALL still auto-resume even when another bot's cleanup is failing.

#### Scenario: Cleanup completes before auto-resume

- **WHEN** the engine starts and a stale-paused paper bot has a cancellation retry, reconcile, or strategy reset pending
- **THEN** the engine completes the cleanup first and then auto-resumes the bot with its normal first-tick grace

#### Scenario: Pending cleanup blocks auto-resume

- **WHEN** cleanup for a stale-paused paper bot cannot complete
- **THEN** the bot remains paused and no orders are placed

#### Scenario: Failed cleanup retries on the next pass

- **WHEN** a cleanup attempt fails and leaves the bot's pending flag set
- **THEN** the engine keeps the auto-resume attempt pending and retries cleanup and resume on a later reconcile pass

#### Scenario: Unrelated bots still auto-resume

- **WHEN** one stale-paused bot has pending cleanup and another eligible stale-paused bot does not
- **THEN** the engine auto-resumes the eligible bot without waiting for the blocked one

#### Scenario: Bots paused after startup are not auto-resumed

- **WHEN** a bot goes stale and is paused after the process captured its startup candidates, while another startup bot's cleanup keeps failing
- **THEN** the later-paused bot remains paused and is not resumed by the retried startup pass
