# Live Trading Runbook

This runbook is the go-live and recovery procedure for real-money trading. Paper
trading is the default and needs none of these steps. Live trading is off unless
an operator deliberately enables the single production gate and then completes
the sequence below.

The named escalation owner for every canary, live safety pause, and rollback is
the repository owner, GitHub `@johnson407`. Do not enable live trading when that
owner is unavailable.

Related documents: [deployment.md](deployment.md), [security.md](security.md),
[runtime.md](runtime.md), [risk.md](risk.md), and [engine.md](engine.md).

## Non-negotiable rules

- `TRADING_LIVE_ENABLED` in the production `.env` is the only live-gate source.
  The application reads `config('trading.live_enabled')`; the engine reads the
  same environment value and reports it in the heartbeat as `live_trading`.
  There is no second switch and no per-service override.
- Production stays at `TRADING_LIVE_ENABLED=false` during every deployment.
  Enabling live trading is a separate, named, post-deploy action.
- Deployment and code changes never set the gate true in a checked-in file.
- Safety pauses cancel pending, open, and partially filled orders and never
  submit a market or liquidation order. Inventory is left for the operator.
- Manual flattening is the only liquidation policy. The engine never does it
  automatically.
- Deploy and verify `fix-engine-alert-delivery` first. Its scheduler scanner and
  heartbeat alert must be running before the gate is enabled.
- Only one live bot may use an exchange credential. Stop the old bot before
  starting a replacement.

## Go-live sequence

Complete these steps in order. Do not skip ahead and do not start a second live
bot while the canary is running.

1. Confirm the production gate is false, deploy this change with live still
   disabled, and run `php artisan live:preflight --pre-enable` until it exits
   zero. Resolve every non-gate blocker before continuing.
2. Complete the **Testnet evidence** record for every drill in
   [the drill matrix](#binance-testnet-drill-matrix). Evidence is mandatory.
3. **Enable the gate** as a named operator decision, recording who decided,
   when, and which evidence supports it.
4. Run **Default `php artisan live:preflight`** with the gate enabled.
5. **Start the canary** inside the numeric cap and observe it for the full
   window.

**Fail-safe:** If the final default `live:preflight` fails after you enable the
gate, set `TRADING_LIVE_ENABLED=false` immediately and stop. Do not retry, do not
start a bot, and do not "fix forward" while the gate is open.

## 1. Pre-enablement preflight: `live:preflight --pre-enable`

Run the read-only preflight in the application container on the NAS:

```sh
cd /share/Docker/trading-bot
docker compose -f docker-compose.prod.yml exec app \
    php artisan live:preflight --pre-enable
```

This mode expects the live gate to be closed. A closed application gate and a
matching engine heartbeat with `live_trading: false` are expected and reported as
passing. Every other check still blocks. The command exits non-zero on any
failure and names the check and its remediation; it never changes a bot, order,
credential, or kill-switch row.

| # | Required check | Passing condition | Remediation when failing |
| --- | --- | --- | --- |
| 1 | Application live gate | `config('trading.live_enabled')` matches this mode: false for `--pre-enable`, true for default mode | Set `TRADING_LIVE_ENABLED` in `.env` and recreate the services |
| 2 | Engine heartbeat freshness | `engine:heartbeat` is present and no older than its 10-second TTL | Start or restart `engine`; check its logs and healthcheck |
| 3 | Engine live flag | Heartbeat reports the live value this mode expects | Fix the engine's `TRADING_LIVE_ENABLED` source and recreate it |
| 4 | App/engine gate match | Heartbeat `live_trading` equals `config('trading.live_enabled')` | Recreate app and engine from the same `.env`; never override one service |
| 5 | Mailer and from address | Mailer is not `log` or `array`, and `MAIL_FROM_ADDRESS` is set | Configure SMTP (or another real transport) and a monitored from address |
| 6 | HTTPS application URL | Outside local/testing, `APP_URL` starts with `https://` | Terminate TLS in front of Nginx and set the public `https://` URL |
| 7 | Horizon | `php artisan horizon:status` reports running | Start or restart `horizon` and check its logs |
| 8 | Scheduler heartbeat | The scheduler heartbeat is present and no older than the three-minute grace | Start or restart `scheduler`; confirm `schedule:work` is the command |
| 9 | Conflicting live credentials | No exchange credential has more than one active live bot | Stop or archive the other bot, then rerun the preflight |

The command output also links to this runbook. Record the full output in the
go-live evidence bundle. Do not enable the gate until every line passes.

### Both preflight modes

| Mode | Command | Gate expectation | Use |
| --- | --- | --- | --- |
| Pre-enablement | `php artisan live:preflight --pre-enable` | Application gate false and matching engine heartbeat false are expected; all other checks must pass | Immediately after deployment and before any gate change |
| Default | `php artisan live:preflight` | Requires both the application gate and the engine heartbeat enabled and matching; exits non-zero otherwise | After the named enablement decision and immediately before starting the canary |

## 2. Testnet evidence

Every drill runs against Binance testnet only, with testnet credentials, sandbox
mode on, and no production keys. Testnet is not a substitute for the canary, but
real-money enablement is not allowed until every row is recorded and passes.

### Binance testnet drill matrix

| Drill | Action | Expected result | Evidence to record |
| --- | --- | --- | --- |
| Start | Start a testnet live bot with the exact `<bot name> on <exchange>` confirmation | Engine status becomes running, heartbeat stays fresh, and no paper fallback occurs | Command response, bot status, heartbeat JSON, engine log lines |
| Ladder placement | Let the bot place its first ladder | Only limit orders appear, with the expected sides, prices, and quantities | Exchange open-orders export plus local `orders` rows |
| Fill | Let one resting order fill | Fill is persisted once, inventory and P&L update once, and the grid advances | Fill ID, order ID, before/after balances |
| Partial fill | Fill part of one order | Order remains partially filled with the correct remaining quantity; no duplicate fill | Order status, fill quantity, local remaining quantity |
| Cancel | Cancel one resting order from the dashboard | Exchange and local state both become canceled; no replacement order appears | Order ID, cancel response, post-cancel open orders |
| Kill switch | Activate the kill switch during live testnet activity | Every bot pauses, all pending/open/partially-filled orders cancel, zero market or liquidation orders appear, and inventory is unchanged | Activity log, order export, before/after base and quote balances |
| Restart with resting orders | Restart the engine while testnet orders are open | Every resting order is reconciled; only unmatched orders with no local record are canceled | Pre/post open orders, reconciliation log, unmatched-order list |
| Invalid credentials | Use a deliberately invalid key/secret on a testnet bot | Start is refused or the bot pauses with a clear credential error; no orders are placed | Error text, risk/alert event, engine log |
| Stale feed | Stop or block the public feed until the bot is stale | Bot pauses after the stale threshold, cancels orders, and does not use an old price | Pause reason `stale_tick`, alert, engine log timing |
| Daily-loss pause | Exceed the configured daily-loss limit in testnet | Bot pauses, cancels orders, and never liquidates inventory | Risk event, order export, unchanged balances |
| Manual flatten | Follow [Manual flatten](#manual-flatten) on remaining testnet inventory | Orders are limit-only, quantity comes from exchange free balance, and final balances are verified | Order IDs, prices, before/after free balances, screenshots |

### Evidence record template

Copy this table per drill and keep it with the release evidence. A drill without
a date, build tag, and artifact link is not evidence.

| Field | Value |
| --- | --- |
| Drill | One row name from the matrix |
| Date and time (UTC) | `YYYY-MM-DDTHH:MM:SSZ` |
| Operator | GitHub handle or full name |
| Build tag | `sha-<commit>` from the running image |
| Environment | Binance testnet, `TRADING_EXCHANGE_SANDBOX=true` |
| Credential label | Non-secret label only; never the key or secret |
| Expected result | Copied from the matrix |
| Observed result | Factual result, including any deviation |
| Order / fill IDs | Exchange and local identifiers |
| Artifact | Path or URL to logs, exports, and screenshots |
| Result | Pass or fail; a fail blocks go-live |

## 3. Enable the gate (named decision)

Only the named escalation owner may authorize this step. Record the decision in
the evidence bundle with the operator, UTC timestamp, testnet evidence location,
canary cap, and the reason live trading is being enabled.

1. Confirm the pre-enablement preflight passed and the testnet evidence is
   complete.
2. Edit the NAS `.env` once:

   ```dotenv
   TRADING_LIVE_ENABLED=true
   ```

3. Recreate the gate-reading services together so no service keeps an old value:

   ```sh
   cd /share/Docker/trading-bot
   docker compose -f docker-compose.prod.yml up -d --force-recreate \
       app engine horizon scheduler
   ```

4. Verify both sides of the gate before running the final preflight:

   ```sh
   docker compose -f docker-compose.prod.yml exec app \
       php artisan tinker --execute="echo config('trading.live_enabled') ? 'true' : 'false';"
   curl -fsS http://127.0.0.1:8000/health/engine
   ```

   The first command must print `true`, and the heartbeat JSON must contain
   `"live_trading":true`. If either is false, stop and fix the source; do not
   start a bot.

Never edit `docker-compose.prod.yml` to enable live trading. The gate lives in
`.env` so the app, engine, Horizon, and scheduler all read one value.

## 4. Default `php artisan live:preflight`

With the gate enabled, run the final blocking check:

```sh
cd /share/Docker/trading-bot
docker compose -f docker-compose.prod.yml exec app php artisan live:preflight
```

Default mode requires the application gate and the engine heartbeat to be
enabled and equal. Any other failure also exits non-zero with remediation. This
is the last gate before real money.

If the final default `live:preflight` fails after you enable the gate, set
`TRADING_LIVE_ENABLED=false` immediately and stop. Recreate the gate-reading
services, confirm the app and heartbeat are false, and preserve the failed
output for review. Do not start the canary with a failing check.

## 5. Canary

The canary is one live bot with a small allocation. No other live bot may run
while it is active.

### Numeric cap

Allocate the lesser of 1% of account equity and a USD 250 absolute cap. Compute
the cap at canary start from the exchange account's USDT valuation and record
the equity, the computed cap, and the bot's capital allocation:

```text
canary_cap = min(0.01 * account_equity_usdt, 250 USDT)
bot_capital_allocation <= canary_cap
```

If equity is unknown or the computed cap is below the exchange minimum order
notional, do not start the canary.

### Observation window

Observe the canary for at least 60 minutes from the first live fill and at least
20 completed ticks, whichever finishes later. Do not add bots, raise the
allocation, or change strategy parameters during the window. If no fill occurs
within 60 minutes, extend once to 120 minutes and then stop the bot.

### Numeric abort threshold

Abort immediately and follow [Rollback](#rollback) if any of the following is
true:

- cumulative realized loss reaches the lesser of 0.5% of account equity and 25%
  of the canary allocation;
- two consecutive ticks fail;
- any order or inventory reconciliation mismatch appears;
- the engine heartbeat is stale for more than 60 seconds;
- a safety pause, alert-delivery failure, or kill switch occurs.

The threshold is a ceiling, not a target. The operator may abort earlier for any
safety concern.

### Canary success

The canary passes only when the full window completes with no abort condition,
every exchange order matches a local record, open orders are zero or expected,
and the recorded P&L and inventory match the exchange. Stop the bot at the end
of the window and keep the gate decision and evidence with the release record.

## Kill switch

The kill switch is the first action for any live incident. It writes
`desired_status=paused` for every non-stopped bot, publishes `kill_switch`, and
cancels open live orders locally when the engine heartbeat is expired or the
command cannot be published.

1. Activate the kill switch in the dashboard (or have the named owner do it).
2. Confirm every bot shows paused or stopped.
3. Confirm the exchange has no open orders for the affected symbols.
4. Confirm base and quote balances are unchanged; the kill switch must not sell
   inventory.
5. If the dashboard is unreachable, stop the engine container to halt new
   placements, then cancel resting orders in the exchange UI. Never place a
   market order as an emergency substitute.
6. If inventory remains and the incident is contained, use
   [Manual flatten](#manual-flatten).

Record the activation time, affected bots, order IDs canceled, balances before
and after, and whether the local fallback path ran.

## Manual flatten

Manual flatten is the only way to liquidate live inventory after a safety pause.
It is a deliberate operator action, never automatic and never a market order.

Preconditions: the kill switch has run, the bot is paused or stopped, the engine
is not placing orders, and the operator has exchange access.

### Order type

Use a **Spot limit order with IOC time-in-force**. Never use a market order, a
stop order, or an OCO as a flatten shortcut. For spot inventory, flatten means
selling the remaining base asset for quote; quote inventory is already the
flattened side.

### Quantity source

Take the quantity from the **exchange free balance** for the base asset: the
`free` field returned by the private account endpoint (or the equivalent Spot
wallet display). Do not use `locked` balance, the local ledger, or the bot's
recorded inventory. Locked quantity belongs to open orders and becomes available
only after they cancel. Round the quantity down to the symbol's `LOT_SIZE`
step; if the remainder is below `MIN_NOTIONAL`, leave the dust and record it.

### Price and protection rule

Capture the best bid at flatten start as the reference price. Place the sell at
the current best bid. If it does not fill, reprice no lower than 0.5% below the
reference price. Attempt at most three times; if still unfilled, stop and
escalate rather than chasing the price down. The protection rule prevents a
manual action from becoming a market-like liquidation during a thin book.

### Verification

After each order:

1. Confirm the exchange reports the order filled or canceled, with the expected
   quantity and price.
2. Confirm exchange open orders for the symbol are zero.
3. Confirm the base free balance is zero or only dust below `MIN_NOTIONAL`.
4. Confirm the dashboard has no open local orders for the bot.
5. Confirm the engine log stops emitting placements and fills for the bot.

### Evidence

Record the symbol, order type and time-in-force, quantity source, quantity, each
limit price and protection bound, exchange order IDs, fill prices, before and
after free balances, timestamps, and a screenshot or JSON export of the final
balances and zero open orders. Attach the engine log slice for the flatten
window.

## Restart with resting orders

The engine reconciles durable local orders with the exchange after a restart. Do
not assume a restart is safe just because the process is healthy.

1. Before restarting, export the exchange's open orders for every live symbol.
2. Restart the engine (or the host) and wait for a fresh heartbeat.
3. Compare every exchange order's client order ID with an open or partially
   filled local `orders` row.
4. Read the engine reconciliation log for the restart window.
5. **Cancel only orders with no matching local record.** Cancel them through the
   exchange, record their IDs, and recheck. Never cancel a matched order merely
   because it is resting; the engine owns it.
6. Treat the bot as healthy only after every resting order is reconciled, every
   orphan is canceled, and the local and exchange views agree.

If reconciliation reports a conflict, leave the bot paused, preserve the logs,
and escalate. Do not restart it again in a loop.

## Rollback

Rollback is mandatory after an abort threshold, a failed final preflight, a
reconciliation conflict, or an operator decision to stop real-money trading.
Perform the steps in this order.

1. Activate the [kill switch](#kill-switch) and confirm all bots pause.
2. Set the gate false in the NAS `.env`:

   ```dotenv
   TRADING_LIVE_ENABLED=false
   ```

3. Recreate the gate-reading services:

   ```sh
   cd /share/Docker/trading-bot
   docker compose -f docker-compose.prod.yml up -d --force-recreate \
       app engine horizon scheduler
   ```

4. Verify both sides are closed. The application must print `false` and the
   heartbeat must contain `"live_trading":false`:

   ```sh
   docker compose -f docker-compose.prod.yml exec app \
       php artisan tinker --execute="echo config('trading.live_enabled') ? 'true' : 'false';"
   curl -fsS http://127.0.0.1:8000/health/engine
   ```

5. If the failure is code-related, deploy the previous known-good tag:
   `./deploy.sh --rollback`. The gate stays false across the image rollback.
6. Cancel any resting orders with no matching local record, following
   [Restart with resting orders](#restart-with-resting-orders).
7. Manually flatten remaining live inventory with
   [Manual flatten](#manual-flatten).
8. Preserve the evidence listed under [Escalation](#escalation). Do not delete
   logs, prune the failed image, or edit database rows.

Do not re-enable the gate until a new named decision records fresh testnet
evidence and a new canary plan.

## Escalation

The named escalation owner is the repository owner, GitHub `@johnson407`. Contact
that owner for any abort, kill-switch activation, reconciliation mismatch, stale
engine heartbeat longer than 60 seconds, failed alert delivery, or unexpected
live P&L. The owner decides whether to continue, roll back, or flatten.

### Evidence to preserve

Preserve the following before changing containers or cleaning up:

- the full `live:preflight --pre-enable` and final `live:preflight` output;
- the testnet evidence record and the signed go-live decision;
- `deploy.log`, `.last-good`, and the running image tag;
- engine logs covering ticks, placements, fills, reconciliation, and errors;
- exchange order and fill exports, including client order IDs;
- before/after free balances and screenshots;
- kill-switch and activity-log timestamps;
- a short incident timeline with UTC times and the operator's actions.

Keep the artifacts with the release record. A rollback or abort without evidence
is treated as an unresolved incident.
