# Tasks

## 1. Configuration parity

- [x] 1.1 Change the production engine service to `${TRADING_LIVE_ENABLED:-false}` and give the app, Horizon, and scheduler the same gate source (shared interpolation or one `env_file`); add `ProductionComposeTest` asserting all four services use the same source and default to false, including a negative case where one service differs, and a Go heartbeat test proving the parsed gate is reported. Verify the config test fails for a deliberate mismatch and passes for disabled and enabled cases without requiring live Redis.
- [x] 1.2 Update `docs/deployment.md` and `docs/security.md` with the single live-gate source, the pre-deploy rule that production must be false, and the separate post-deploy enablement procedure; verify the documented variable name matches Compose and config.

## 2. Single live bot per credential

- [x] 2.1 Add `LiveCredentialPolicy` with active meaning non-archived and (`status` running/paused OR `desired_status` running). Enforce it in `CreateBot` and `StartBot` inside a transaction that locks the `exchange_credentials` row, excludes the bot being started, and rejects a live bot with no credential. Verify tests for running, in-flight start, resuming the same paused bot, after stop/archive, unaffected paper bots, null credential, and concurrent starts producing one winner.
- [x] 2.2 Add `LiveCredentialConflictException` for both a conflicting active bot and a missing live credential; catch it in `BotController::store` as a `mode` validation error and in the start action as an `action` error. Verify the operator gets a redirect with a message and no second bot row or published start, not an HTTP 500, including a create-form test for the missing-credential case.

## 3. Manual liquidation invariant

- [x] 3.1 Add a Go regression test with a recording exchange fake covering daily loss, protective exit, exchange failure threshold, repeated tick failure, stale tick, live gate, reconciliation conflict, strategy stop, unsupported exchange, and kill switch. Verify each cause cancels pending, open, and partially-filled orders, submits zero market or liquidation orders, and leaves base/quote inventory unchanged; update `docs/risk.md` and run the full Go engine suite.

## 4. Live preflight

- [x] 4.1 Add a scheduler cache heartbeat updated every minute and checked with a three-minute grace; verify a command test distinguishes fresh, stale, and missing values.
- [x] 4.2 Add `live:preflight` with required checks for the application live gate, engine heartbeat freshness and live flag, app/engine gate mismatch, mailer plus from address, HTTPS URL outside local/testing, Horizon, scheduler heartbeat, and conflicting live credentials using the policy's active definition. Add `--pre-enable` to expect a closed gate and matching disabled heartbeat while still blocking on every other check; default mode requires both enabled. Verify tests for all-pass exit zero, every failure exit non-zero with remediation, pre-enable pass and still-blocking cases, final-mode gate requirement, secret redaction, and no change to bots, orders, credentials, or kill-switch state.
- [x] 4.3 Document both preflight modes under a distinct `## Live-trading preflight` section in `docs/deployment.md`, link `docs/live-trading.md` from the documented checks and the command output, and verify every required check in code appears in the documented list.

## 5. Go-live runbook and production controls

- [x] 5.1 Add `docs/live-trading.md` with the Binance testnet drill matrix and evidence template, numeric canary cap (lesser of one percent of equity and an absolute cap), observation window, numeric abort threshold, kill switch, manual flatten (order type, exchange free-balance quantity source, protection rule, verification, evidence), restart-with-resting-orders reconciliation including cancelling only orders with no local record, executable rollback, a named escalation owner with the evidence to preserve, and the sequence pre-enable preflight, testnet evidence, enable gate, default preflight, canary.
- [x] 5.2 Add `tests/Unit/LiveTradingDocsTest.php` asserting the runbook exists and contains, in order, the `--pre-enable` preflight, testnet evidence, gate enablement, default `live:preflight`, and canary start; that it states the gate is set false immediately if the final preflight fails; and that it includes the numeric canary cap/window/abort threshold, both preflight modes, manual-flatten order type and free-balance quantity source, restart-with-resting-orders unmatched-order cancellation, rollback, the named escalation owner with the evidence to preserve, and links from deployment and security docs.
- [x] 5.3 Document TLS/HSTS termination, a non-log mailer with a from address, trade-only API keys with withdrawals disabled, IP allowlisting, and `.env` mode 600 on the NAS (644 only for the local bind mount); verify deployment and security docs name each control and link the runbook.
- [x] 5.4 Correct stale statements in `docs/runtime.md`, `docs/security.md`, `engine/README.md`, and `docs/next-features.md` that omit the production engine or describe superseded behavior; verify each corrected statement matches `docker-compose.prod.yml` and the current CI workflows.

## 6. Integration verification

- [x] 6.1 Run `live:preflight --pre-enable` with live trading disabled and simulated non-gate checks and verify it exits zero when they pass and blocks when one fails; run default `live:preflight` with the gate disabled and verify it reports the closed-gate blocker without changing state.
- [x] 6.2 Run the full PHP suite, frontend build, full engine suite, and `openspec validate live-trading-readiness --strict`; verify all pass and record the exact commands.

## Workflow follow-up

- Deploy and verify `fix-engine-alert-delivery` before this change; do not archive readiness before its scanner and heartbeat alert are running.
- Request review of the configuration, credential policy, preflight, docs, and specs before merging.
- Archive the change with the OpenSpec archive skill after review and verification.

## Verification record

- Full PHP suite (with Go plan server): `artisan test` -> 1 skipped, 777 passed (5446 assertions).
- Full engine suite: `gofmt -w internal/runtime/*.go internal/heartbeat/*.go && go vet ./... && go test ./... -count=1` -> all packages pass; runtime 8.036s, heartbeat 0.013s.
- Frontend build: `npm run build` -> 830 modules transformed; production build succeeded.
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` -> no errors.
- Pint: all 20 changed PHP files pass.
- `openspec validate live-trading-readiness --strict` -> valid.
- `LivePreflightTest` exercises both `--pre-enable` and default modes, including the closed-gate blocker and every required failure without changing trading state.
