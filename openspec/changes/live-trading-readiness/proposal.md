# Proposal

## Why

`docker-compose.prod.yml` hardcodes the engine's `TRADING_LIVE_ENABLED` to false while the application reads `.env`, so enabling live trading makes Laravel accept a start the engine refuses. The live-start guard also checks only the engine's current status, so two starts can both pass before either status changes. There is no single-live-bot credential policy, no executable preflight, and no rehearsed manual-liquidation or testnet procedure. The operator needs one consistent gate, an enforceable credential rule, and evidence-based go-live steps before real money is exposed.

## What Changes

- Make production source the app and engine live gate from the same `TRADING_LIVE_ENABLED` value, default false; assert app, engine, Horizon, and scheduler use the same source in a config test and verify the runtime heartbeat flag in preflight.
- Enforce one active live bot per exchange credential, where active includes a bot whose desired status is already running but whose engine status has not caught up. Creation and start run under a credential-row lock, exclude the bot being started, and reject live bots without a credential.
- Surface policy rejections as operator-facing validation or action errors instead of HTTP 500 responses.
- Keep manual liquidation as the only policy: every safety pause cancels pending, open, and partially-filled orders, never submits a market or liquidation order, and leaves inventory unchanged.
- Add a read-only `live:preflight` command with blocking checks for the app live gate, engine heartbeat freshness and live flag, mailer, HTTPS URL, Horizon, scheduler heartbeat, and conflicting live credentials. Output names each failure and its remediation and redacts secrets. `--pre-enable` expects the closed gate so deployment can preflight before enablement; default mode requires the gate enabled.
- Add `docs/live-trading.md` with a Binance testnet drill matrix and evidence record, a numeric canary cap/window/abort threshold, kill-switch and manual-flatten steps, restart-with-resting-orders reconciliation, rollback, and a named escalation owner.
- Require the production live gate to be false during deployment. After testnet evidence is recorded, enabling live trading is a separate named operator action followed by a passing default preflight and then the canary.
- Document TLS/HSTS termination, a non-log mailer with a from address, trade-only keys with withdrawals disabled, IP allowlisting, and `.env` mode 600 on the NAS.
- Correct stale deployment, security, runtime, and engine documentation that says the engine is not part of production or CI.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `deployment`: production live-gate parity, a blocking read-only live preflight, and an evidence-based go-live/recovery runbook.
- `risk-controls`: one active live bot per exchange credential, including in-flight starts, and manual liquidation on every safety pause.

## Impact

- Laravel: `LiveCredentialPolicy`, a dedicated conflict exception covering credential conflicts and missing live credentials, `CreateBot`/`StartBot` and `BotController` error handling, `live:preflight`, a scheduler heartbeat command, config and feature tests, and docs tests.
- Configuration/deployment: `docker-compose.prod.yml`, application config tests, production environment checklist, and the live-trading runbook.
- Engine: a heartbeat test confirming the parsed live gate; no order-placement change and no automatic liquidation.
- Documentation: new `docs/live-trading.md`; updates to deployment, security, risk, runtime, and engine docs.
- Database: no migration; the credential rule is enforced transactionally in Laravel.
