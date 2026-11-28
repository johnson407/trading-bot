# Design

## Context

See `proposal.md` - Why. Live order placement is already gated in Laravel and rechecked by the engine. The remaining gaps are configuration drift, credential concurrency, an executable readiness check, and an operator procedure. Strategy defects #37-#41 are already fixed on `main`; this change does not revisit them.

`CreateBot` resolves the user's credential by exchange and persists `exchange_credential_id`, possibly null. `StartBot` calls `LiveModeGuard::assertCanStart` before updating `desired_status` and publishing `bot.start`; the engine changes `status` asynchronously. The engine heartbeat exposes `live_trading` and can be read through `EngineHeartbeat`.

## Goals / Non-Goals

**Goals:**

- Make production enablement a single, consistent decision across Laravel, Compose, and the running engine.
- Prevent two live bots from sharing one exchange credential, including in-flight starts.
- Give operators a read-only check and a rehearsed procedure before real money.
- Make manual liquidation the explicit, tested policy for every safety pause.

**Non-Goals:**

- Changing strategy behavior, order placement, or the safety pause semantics already implemented.
- Automatic liquidation or market orders.
- Multi-credential portfolio allocation or aggregate exposure across accounts.
- Fixing #42 parity items.
- Enabling live trading in any checked-in configuration.

## Decisions

### One environment value, interpolated everywhere

Change the production engine service to `${TRADING_LIVE_ENABLED:-false}` and give the app, Horizon, and scheduler the same interpolation or rely on one shared `env_file`, so no service can see a different gate than the engine. Add `ProductionComposeTest`, which asserts the app, engine, Horizon, and scheduler live-gate entries use the same source and default to false, including a negative case where one service differs; it does not require Redis. Add a Go heartbeat test proving the engine parses `TRADING_LIVE_ENABLED` and reports it. `live:preflight` compares the runtime heartbeat flag with the application gate and fails on mismatch.

Hardcoding was rejected because it makes the application and engine disagree by construction. Duplicating the flag in a second engine-only variable was rejected because it creates another way to drift. The config test does not read live Redis; only preflight checks the running engine.

### Credential lock plus policy check

Add `LiveCredentialPolicy` used by both `CreateBot` and `StartBot`. A bot is active when it is non-archived and either status is running/paused or desired status is running. The policy rejects a live bot when another active live bot has the same `exchange_credential_id`, excluding the bot being started. The check and the creation or desired-status update run in one transaction that locks the `exchange_credentials` row, serializing concurrent create/start requests. A live bot without a credential is rejected at creation.

Add `LiveCredentialConflictException` for both an active credential conflict and a missing live credential. `BotController::store` catches it and returns a `mode` validation error; the start action catches it and returns an `action` error, so the operator gets a redirect with a message instead of HTTP 500. A live bot without a credential is rejected by throwing this exception with a \"live bot requires an exchange credential\" message.

A database partial unique index was considered. It would enforce the invariant at the storage layer, but status transitions are engine-owned and a unique violation would surface as a runtime write failure rather than an operator-facing rejection. The desired-status predicate closes the in-flight window that a status-only check would miss.

### Read-only preflight with explicit blockers

Add `live:preflight`, which reports each check and exits non-zero on any blocker:

- application live gate enabled in `config/trading.php`;
- engine heartbeat fresh and reporting live trading enabled;
- engine heartbeat gate equal to the application gate;
- mailer not `log`/`array` and a from-address configured;
- `APP_URL` uses HTTPS outside local/testing;
- Horizon reports running;
- scheduler heartbeat fresh;
- no exchange credential has more than one active live bot, using the same active definition as the policy.

Scheduler liveness uses a cache heartbeat written by a scheduled closure every minute, with a three-minute grace. Checking only `schedule:list` would prove registration, not execution. Queue liveness uses Horizon's status command rather than dispatching a probe job, keeping the command read-only.

Every required check is blocking; there is no separate advisory class whose result would be ambiguous. Output redacts API keys, secrets, passphrases, database passwords, and `APP_KEY`. The command reads config, Redis, and database state only; it never updates a bot, order, credential, or kill-switch row.

`--pre-enable` keeps deployment safe: with the gate expected closed, it treats the closed application gate and matching disabled engine heartbeat as expected and still blocks on every other check. Default mode requires both enabled, so the operator enables the gate as a named decision and then runs the final preflight before starting the canary. If that final preflight fails, the operator immediately sets the gate false and stops.

### Manual liquidation as an explicit invariant

No production order path changes. Add a Go regression test using a recording fake exchange that drives every automatic pause cause: daily loss, protective exit, exchange failure threshold, repeated tick failure, stale tick, live gate, reconciliation conflict, strategy stop, unsupported exchange, and kill switch. Each cause must cancel pending, open, and partially filled orders, submit zero market or liquidation orders, and leave base and quote inventory unchanged. Document the operator flatten procedure in the runbook.

### Runbook as an executable gate

Add `docs/live-trading.md` with the testnet drill matrix and evidence template, a numeric canary cap (lesser of one percent of equity and a stated absolute cap), observation window, numeric abort threshold, kill-switch and manual-flatten steps, restart-with-resting-orders reconciliation, rollback, and a named escalation owner. Link it from deployment and security docs. Add `tests/Unit/LiveTradingDocsTest.php` so the required sections cannot silently disappear.

Real-money enablement requires, in order: recorded testnet evidence, the named operator decision to enable the gate, a passing default preflight with the gate enabled, and then the canary. Deployment keeps the production gate false until that named decision.

### Deploy alert delivery first

`live:preflight` and the runbook assume engine safety events produce alerts. Deploy and verify `fix-engine-alert-delivery` (scheduler scanner and heartbeat alert running) before this change. Do not archive this change before the alert change is deployed.

## Risks / Trade-offs

- [Single-credential policy blocks a legitimate replacement] -> The operator stops the old bot first; the error names the active bot.
- [Create/start race bypasses the policy] -> Credential-row lock serializes both paths; desired status closes the in-flight window; the test covers concurrent starts.
- [Preflight false failure right after deploy] -> Scheduler heartbeat has a three-minute grace and the output names the missing signal.
- [Compose interpolation is quoted incorrectly] -> `ProductionComposeTest` compares the app, engine, Horizon, and scheduler service entries and their defaults, including a one-service mismatch case.
- [Operators skip the runbook] -> Deployment docs and preflight output link to it; go-live evidence is required by the procedure.
- [Manual flatten is performed incorrectly] -> Runbook specifies order type, free-balance quantity source, protection rule, verification, and evidence capture.
- [Docs drift from behavior] -> `LiveTradingDocsTest` plus updates to deployment, security, risk, runtime, and engine docs.
- [Live is enabled during deployment] -> Runbook requires recording the production gate as false before deploy and confirming app plus heartbeat false after.

## Migration Plan

1. Deploy `fix-engine-alert-delivery` first; verify its scheduler scanner and heartbeat alert are running.
2. Confirm `TRADING_LIVE_ENABLED=false` in the production environment and record the value.
3. Deploy configuration parity, the credential policy, preflight, scheduler heartbeat, docs, and runbook with live trading still disabled.
4. Run `live:preflight --pre-enable` in production and resolve every non-gate blocker.
5. Record the Binance testnet drill and evidence.
6. Enable the production gate as the named operator decision.
7. Run default `live:preflight`; if it fails, set `TRADING_LIVE_ENABLED=false` immediately and stop.
8. Start the tiny canary within the numeric cap.
9. Rollback: set `TRADING_LIVE_ENABLED=false`, redeploy the previous images, verify the engine heartbeat is false, cancel unmatched resting orders, and manually flatten remaining inventory.

No database migration is required.

## Open Questions

None.
