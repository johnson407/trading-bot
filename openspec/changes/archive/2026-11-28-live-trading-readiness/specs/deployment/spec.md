# Spec Delta

## ADDED Requirements

### Requirement: Live-trading configuration parity

Production SHALL source the application and engine live gate from the same `TRADING_LIVE_ENABLED` value, defaulting to false when unset. A configuration test SHALL assert the production app, engine, Horizon, and scheduler services use the same gate source and SHALL fail on disagreement. A Go heartbeat test SHALL prove the engine reports the parsed gate value. `live:preflight` SHALL compare the runtime heartbeat flag with the application gate and fail when they differ.

#### Scenario: Live trading is disabled by default

- **WHEN** `TRADING_LIVE_ENABLED` is unset in production configuration
- **THEN** the application config, the production engine composition, and the running engine heartbeat all report live trading as disabled

#### Scenario: Enabled configuration agrees everywhere

- **WHEN** `TRADING_LIVE_ENABLED` is set to true for production
- **THEN** the application config, the production engine composition, and the running engine heartbeat all report live trading as enabled

#### Scenario: Compose and config disagreement fails the test

- **WHEN** the production engine service live-gate entry differs from the application config
- **THEN** the configuration test fails and names the mismatched values

#### Scenario: Runtime gate mismatch fails preflight

- **WHEN** the engine heartbeat reports a different live-gate value than the application config
- **THEN** `live:preflight` reports a blocking failure with remediation

### Requirement: Live readiness preflight

The system SHALL provide a read-only `live:preflight` command. Required checks: application gate, engine heartbeat freshness and live flag, app/engine gate match, mailer and from address, HTTPS URL outside local/testing, Horizon, scheduler heartbeat, and conflicting live credentials. Any failure SHALL exit non-zero with check and remediation. Output SHALL redact secrets. The command SHALL NOT change bot status, desired status, orders, credentials, or kill-switch state.

#### Scenario: Ready configuration passes

- **WHEN** every required live-readiness check passes
- **THEN** the command exits zero and reports each check as passing

#### Scenario: Each blocker exits non-zero

- **WHEN** any blocking check fails, including a closed app gate, stale heartbeat, disabled engine live flag, log-only mailer, non-HTTPS URL, inactive queue worker, stale scheduler, or conflicting live credential
- **THEN** the command exits non-zero and names that check and its remediation

#### Scenario: Engine live flag mismatch blocks

- **WHEN** the application gate is enabled but the engine heartbeat reports live trading disabled
- **THEN** the command exits non-zero with an engine-gate mismatch remediation

#### Scenario: Stale scheduler blocks

- **WHEN** the scheduler heartbeat is missing or older than the three-minute grace
- **THEN** the command exits non-zero and identifies the scheduler as inactive

#### Scenario: Secret values are redacted

- **WHEN** the preflight reports configuration values that contain secrets
- **THEN** API keys, secrets, passphrases, database passwords, and the application key are redacted

#### Scenario: Preflight is read-only

- **WHEN** the preflight runs against a configured account
- **THEN** it reads configuration and state only and makes no change to bots, orders, credentials, or the kill-switch

### Requirement: Pre-enablement preflight mode

`live:preflight --pre-enable` SHALL expect the live gate closed. It SHALL treat a closed application gate and matching disabled engine heartbeat as expected and SHALL block on every other required check. Default `live:preflight` SHALL require the application gate and engine heartbeat enabled and SHALL fail on mismatch.

#### Scenario: Pre-enablement passes with the gate closed

- **WHEN** `live:preflight --pre-enable` runs with the application gate and engine heartbeat disabled and every other check passes
- **THEN** the command exits zero and reports the closed gate as expected

#### Scenario: Pre-enablement still blocks other failures

- **WHEN** `live:preflight --pre-enable` runs with the gate closed and another required check fails
- **THEN** the command exits non-zero and names that check and its remediation

#### Scenario: Final preflight requires the gate enabled

- **WHEN** default `live:preflight` runs after the operator enables the gate and before any live bot starts
- **THEN** the command exits non-zero if the application gate or engine heartbeat is disabled or mismatched

### Requirement: Live go-live and recovery runbook

The deployment runbook SHALL be `docs/live-trading.md` and SHALL cover testnet drills and evidence, a numeric canary cap, observation window, abort threshold, kill switch, manual flattening, restart with resting orders, rollback, and a named escalation owner. Real-money enablement SHALL require, in order, completed testnet evidence, the named operator decision to enable the gate, a passing default `live:preflight` with the gate enabled, and then the canary.

#### Scenario: Testnet drill evidence is required

- **WHEN** an operator prepares to enable real-money trading
- **THEN** the runbook requires recorded evidence for start, ladder placement, fill, partial fill, cancel, kill-switch, restart with resting orders, invalid credentials, stale feed, daily-loss pause, and manual flatten

#### Scenario: Canary has numeric limits

- **WHEN** the first real-money canary is started
- **THEN** the runbook specifies the lesser of one percent of equity and an absolute cap, the observation window, a numeric abort threshold, and the exact rollback action

#### Scenario: Restart reconciles resting orders

- **WHEN** the live engine restarts while exchange orders remain open
- **THEN** the runbook directs the operator to verify every resting order was reconciled and to cancel only orders with no matching local record before treating the bot as healthy

#### Scenario: Manual flatten is actionable

- **WHEN** a safety pause leaves live inventory open
- **THEN** the runbook specifies the order type, exchange free-balance quantity source, price or protection rule, verification step, and evidence to record

#### Scenario: Deployment keeps the gate closed

- **WHEN** parity, credential, preflight, or runbook changes are deployed
- **THEN** the runbook requires the production `TRADING_LIVE_ENABLED` value to be confirmed false before deployment and both the app config and engine heartbeat to remain false after deployment

#### Scenario: Final preflight precedes the canary

- **WHEN** the operator enables the production gate and prepares to start the canary
- **THEN** the runbook requires default `live:preflight` to pass and requires the gate to be set false immediately if it fails

#### Scenario: Rollback is executable

- **WHEN** the canary aborts or the operator rolls back
- **THEN** the runbook gives the exact gate, image, order, and inventory steps, including manual flattening when needed

#### Scenario: Escalation owner is named

- **WHEN** an incident occurs during the canary or a live safety pause
- **THEN** the runbook names the escalation owner and the evidence to preserve
