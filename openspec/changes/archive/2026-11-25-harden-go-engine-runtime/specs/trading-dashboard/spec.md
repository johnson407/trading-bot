## ADDED Requirements

### Requirement: Live mode status is explicit

The dashboard and bot setup SHALL present live mode as an available, gated mode rather than as unimplemented. When a bot or credential is configured for testnet, the UI SHALL state that orders target the exchange testnet. When `TRADING_LIVE_ENABLED` is false, the UI SHALL make clear that live orders are blocked by the gate and that the bot is not silently running as paper.

#### Scenario: Testnet is labeled

- **WHEN** a live bot is configured to use testnet
- **THEN** the dashboard or bot view states that the bot targets the exchange testnet

#### Scenario: Closed gate is visible

- **WHEN** `TRADING_LIVE_ENABLED` is false and a user views a live bot
- **THEN** the UI indicates that live execution is gated off and does not imply that orders are being sent
