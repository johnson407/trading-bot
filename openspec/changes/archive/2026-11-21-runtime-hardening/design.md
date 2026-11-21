# Design

## Startup reconciliation

The scheduler container command becomes
`sh -c "php artisan bots:reconcile || true; exec php artisan schedule:work"`.
Conflicts leave the affected bot paused but do not block the scheduler, since
other bots still need ticks and an operator can inspect the recorded conflicts.

## Dispatch guard

`bots:dispatch` uses `Cache::add('bot-dispatch:{id}', 1, interval)` before
queueing. The job's existing unique lock and overlap middleware remain as the
second line of defense.

## Client reuse

`ExchangeClientFactory` is already a container singleton. It now caches live
clients by `exchange|credential_id` (a CCXT instance caches the credential it
authenticated with) and paper clients by exchange. Test overrides still take
precedence and are never cached.

## Recent prices and grid recreation

Each tick appends the last price to `bots.settings.recent_prices`, keeping 30
entries, and passes them to `MarketSnapshot`. When `syncGrid` replaces the
active grid, the pipeline re-evaluates `onTick` after the old orders are
canceled; this makes trailing intents reflect the new level sides instead of
reusing the pre-cancellation snapshot.

## Lifecycle

Cancellations produced by strategy decisions and grid replacement are folded
through `onCancel`, preserving returned state. `decision->stop` and
`shouldStop()` share one stop procedure that pauses, records the reason, and
cancels open orders while recording cancellation failures. A bot that has
completed a tick but has no persisted strategy state is paused rather than
silently reinitialized.

## Numerics and trading rules

`Number::decimalString` converts ints, decimal strings, exponent strings and
floats into plain decimals. `placeIntent` rounds price and quantity to the
market's precision and rejects orders below the minimum amount or notional with
a risk event, using the rounded values for risk checks and placement.

## Risks

- Re-evaluating `onTick` after grid replacement makes the strategy see canceled
  orders; strategies that rely on pre-cancellation snapshots must be idempotent.
- Cached clients keep credentials for the lifetime of a worker; the key includes
  the credential id to prevent cross-credential reuse.
- The dispatch guard consumes a cache entry even if the queue later drops the
  job; the interval retry covers that case.
