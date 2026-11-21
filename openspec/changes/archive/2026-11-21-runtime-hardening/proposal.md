# Runtime hardening

## Why

The MVP could resume a running bot without reconciling first, never delivered
recent prices to the dynamic strategy, left holes after a trailing recenter,
ignored strategy cancellation callbacks and stop decisions, silently
reinitialized missing strategy state, rebuilt an exchange client on every tick,
and could parse small CCXT floats as scientific notation. Live orders were also
sent without exchange precision or minimum checks.

## What Changes

- Reconcile running bots before the scheduler starts work.
- Guard dispatch so queue latency cannot enqueue duplicate ticks.
- Reuse exchange clients per exchange and credential; raise the stale threshold.
- Deliver a rolling recent-price window to strategies and re-evaluate the
  strategy after a grid is recreated.
- Invoke `onCancel`, honor `StrategyDecision::stop`, and pause a bot whose
  persisted strategy state is missing after it has run.
- Normalize CCXT numerics and enforce price/amount precision, minimum amount
  and minimum notional before placement.

## Impact

- Affected specs: `bot-runtime`, `strategy-engine`
- Affected code: `BotTickPipeline`, `TrailingGridStrategy`, `StrategyStateLoader`,
  `ExchangeClientFactory`, `DispatchBotTicks`, `CcxtExchangeClient`,
  `Number`, scheduler compose command, runtime documentation.
