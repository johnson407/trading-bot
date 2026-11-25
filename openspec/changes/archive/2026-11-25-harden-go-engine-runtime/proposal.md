# Proposal

## Why

A post-apply audit of `adopt-hybrid-go-engine` found three important gaps in the shipped Go runtime. Paper ticks fetch a fresh public REST price on every evaluation and never subscribe the already-built WebSocket feed to running bots. Laravel still loads PHP strategy classes and runs `onTick` for setup plan preview, so task 8.2 (delete the PHP strategies) is unfinished and preview can drift from the engine. Live execution is explicitly unimplemented in `engine/main.go` even though task 8.6 was ticked: a live bot is paused and no private call is made.

Johnson confirmed he wants live trading in this change, not a "live unavailable" label. The work stays propose-only here. Apply MUST keep `TRADING_LIVE_ENABLED` default false, prove live place/cancel/reconcile on testnet or fakes first, and MUST NOT run any task against a real-money live account without Johnson's explicit yes for that exact action.

## What Changes

- Subscribe the engine's public market-data feed to running bots' symbols dynamically. `Runtime` reads the cached last price, with REST fallback and a staleness bound of 15 seconds or half of `TRADING_TICK_INTERVAL`, whichever is larger. No private market-data calls.
- Finish PHP strategy cleanup: Go is the single source of truth for strategy evaluation and plan preview. Replace the PHP `onTick` preview path (`GridPlanEvaluator`, `app/Strategies/*`, `AppServiceProvider` bindings) with a thin Laravel client of an internal engine evaluate endpoint, so preview and save keep agreeing.
- Implement real live place, cancel, and reconcile in the Go engine for Binance and a MAX adapter. Use a CCXT-equivalent Go client; honor any existing NAS relay or proxy as transport only and do not change that relay. Enforce `TRADING_LIVE_ENABLED` default false, apply risk gates before every live order, use idempotent client order IDs, reconcile on startup and periodically, never fall back from live to paper, and keep the Laravel `CancelBotOrders` emergency path. The first and default soak is Binance testnet only; the MAX adapter is proven on fakes only (no MAX live or testnet proof in this change).
- Small cleanups: stale comments (`engine/main.go` command-processor comment, `heartbeat.NewStub` name), delete unused leftover PHP value objects after verification (`TickResult`, `ReconcileResult`, `CapacityReport`), and remove `last_error` plus other engine-owned columns from `Bot` `$fillable`.

## Capabilities

### New Capabilities

- None. Live execution extends `exchange-connectivity`, `bot-runtime`, and `risk-controls` rather than introducing a separate capability.

### Modified Capabilities

- `exchange-connectivity`: Running-bot WebSocket subscriptions and cached last-price reads with REST fallback and a staleness bound; live execution moves from unimplemented to the Go engine; existing testnet/sandbox flags are honored.
- `bot-runtime`: Ticks consume the cached public feed; the engine places and syncs live orders; periodic as well as startup reconcile.
- `bot-provisioning`: Plan preview and create/update evaluation are performed by the Go engine, not PHP strategy `onTick`.
- `strategy-engine`: Laravel no longer hosts runtime strategy implementations; Go is the only evaluator.
- `risk-controls`: Risk gates are enforced in the engine immediately before every live order; a closed live gate still pauses a live bot and never converts it to paper.
- `trading-dashboard`: Live mode remains selectable, but testnet versus real-money live is explicit, and production live stays gated.
- `grid-trading`: Live grid orders are placed and canceled by the Go engine.

## Impact

- **Code:** `engine/` market data, runtime, a new live exchange client, and an internal plan-evaluate HTTP endpoint; Laravel preview/validation clients; deletion of PHP strategy runtime classes once preview is wired; `Bot` mass-assignment hygiene.
- **Docs:** `docs/engine.md`, `docs/runtime.md`, `docs/exchanges.md`, `docs/risk.md`, `docs/security.md`, and the README, including the testnet-first rollout and the real-money approval rule.
- **Ops:** Default compose and production remain `TRADING_LIVE_ENABLED=false`. This change does not start or stop laptop Horizon/scheduler, and does not delete or merge `origin/hybrid-engine` (stale after the squash to `main`; operators may tidy it later). Any existing NAS relay or HTTP proxy used to reach exchanges is left in place and only reused as transport.

## Confirmed decisions

Johnson asked for one OpenSpec change covering the three audit findings, then asked to get live trading into that same change rather than labeling live as unavailable.

He later resolved the remaining open questions:

- The first and default soak is Binance testnet only.
- Build the MAX adapter in this change, but test it only on fakes. Do not run a MAX live or MAX testnet proof.
- Keep the staleness bound at 15 seconds, or half of `TRADING_TICK_INTERVAL` when that is larger.
- Run a proposal code review before apply; do not fix review findings until Johnson decides.

Apply remains testnet- and fake-first. No task may call a real-money live account without Johnson's explicit yes for that exact action.

A proposal review then required these hardenings, now recorded in design, tasks, and specs: persist client order IDs before any network place and look them up before retry or startup; bump ID generation only after fill or cancel; constrain IDs to venue format rules; hard-fail the Binance testnet suite on production hosts, keys, or URLs; fail closed if a relay would rewrite testnet to production; apply partial fills in live reconcile; keep paper reconcile local; bind the evaluate endpoint off the host (token if the port is published); cover MAX on fakes in named tests; prefer the real engine in Pest; respect rate-limit budgets; leave the opt-in suite off during default verify; and document enabling `max` on the allowlist without implying a MAX soak.
