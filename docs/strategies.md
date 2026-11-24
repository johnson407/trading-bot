# Strategies

The bot engine is strategy-agnostic. A strategy decides what grid to build and which orders to
request; the engine owns persistence, exchange calls, risk checks, and order/fill bookkeeping.
Strategies never read the database and never call an exchange.

In the hybrid runtime the executing strategies live in
`engine/internal/strategy`. The PHP classes under `app/Strategies` remain only
for the Laravel create/edit ladder preview (`POST /bots/preview`) and are not the
tick runtime. Changes to a built-in rule must be made in both places until the
preview is served from the engine; the Go fixture suite is the runtime authority.

For the full list of bot fields, their validation rules, and their runtime effects, see
[Bot parameters](bot-parameters.md).

## The contract

Every strategy implements `App\Domain\Strategy\Contracts\StrategyInterface`:

```php
interface StrategyInterface
{
    public function key(): string;
    public function initialize(StrategyInput $input): StrategyDecision;
    public function onTick(StrategyInput $input): StrategyDecision;
    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision;
    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision;
    public function shouldStop(StrategyInput $input): bool;

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array;
}
```

`StrategyInput` is immutable and contains four value objects:

- `market` — symbol, current price, optional bid/ask, recent prices, and timestamp.
- `bot` — bot id, base/quote inventory, average entry price, realized P&L, capital, open orders.
- `config` — the strategy configuration stored on the bot.
- `state` — the versioned strategy state resumed from the database.

`StrategyDecision` is also immutable. It carries the next `state`, the grid `levels`, the `orders`
to place, an optional `stop` flag, domain `events`, and `cancelClientOrderIds`.

## Registration

The PHP preview strategies are registered in `AppServiceProvider` for
`StrategyRegistry` and the preview evaluator. A new runtime strategy must also be
added to the Go engine's strategy factory and fixture tests; the PHP registration
alone does not make it tradable.

Register a strategy with the `StrategyRegistry` singleton, normally in
`AppServiceProvider::boot()`:

```php
use App\Domain\Strategy\StrategyRegistry;

public function boot(): void
{
    $this->app->make(StrategyRegistry::class)->register(new MyStrategy);
}
```

The registry rejects duplicate keys and resolves strategies by their stable `key()`. The key is
stored in `strategy_configs.strategy_key`, so renaming a key requires migrating existing bots.

## State rules

- `initialize()` must be idempotent. It should return the existing state unchanged when
  `$input->state->isNew()` is false.
- Return the next state from every hook through `StrategyDecision`.
- State is persisted as JSON through `StrategyStateSerializer` with
  `{ "version": 1, "data": { ... } }`. The version is checked on resume; an unsupported version
  pauses the bot and records an activity error rather than guessing.
- State must be JSON-serializable and free of floats. Use `Decimal` strings for money.
- `state_version` is written alongside the JSON for diagnostics.

## Configuration

`StrategyConfiguration` exposes typed accessors: `get`, `has`, `string`, `decimal`, `int`, `bool`.
Missing or invalid values should throw `InvalidStrategyConfigurationException::forStrategy()`.
Money and quantities must be requested with `decimal()` so they stay exact strings.

## Built-in strategies

| Key | Purpose | Required configuration |
| --- | --- | --- |
| `simple-grid` | Static arithmetic or geometric ladder | `center_price`, `levels_per_side` or `level_count`, `spacing_type`, `spacing_value`, `order_quantity` |
| `trailing-grid` | Recenters when price moves beyond a distance | Simple grid keys plus `trailing_distance` |
| `martingale-grid` | Increases order size after fills with hard caps | Simple grid keys plus `size_multiplier`, `max_order_quantity`, `max_increases` |
| `dynamic-grid` | Sets spacing from recent volatility | `levels_per_side`, `order_quantity`, `min_spacing`, `max_spacing`, optional `volatility_multiplier`, `initial_spacing`, `recalculation_interval_seconds` |

## Minimal custom strategy

The complete, runnable example lives at `tests/Fixtures/DocumentedCustomStrategy.php`. It builds a
two-level ladder and demonstrates every interface method:

```php
namespace Tests\Fixtures;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Support\Decimal;

class DocumentedCustomStrategy implements StrategyInterface
{
    public const KEY = 'documented-custom';

    public function key(): string { return self::KEY; }

    public function initialize(StrategyInput $input): StrategyDecision
    {
        if (! $input->state->isNew()) {
            return StrategyDecision::idle($input->state);
        }

        return StrategyDecision::withLevels(
            $input->state->merge(['initialized' => true]),
            $this->getGridLevels($input),
        );
    }

    public function onTick(StrategyInput $input): StrategyDecision
    {
        return StrategyDecision::withLevels($input->state, $this->getGridLevels($input));
    }

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision
    {
        return StrategyDecision::idle($input->state->merge(['last_fill' => $fill->exchangeFillId]));
    }

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision
    {
        return StrategyDecision::idle($input->state);
    }

    public function shouldStop(StrategyInput $input): bool { return false; }

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array
    {
        return [
            GridLevelPlan::make(1, Decimal::of('99'), OrderSide::Buy),
            GridLevelPlan::make(2, Decimal::of('101'), OrderSide::Sell),
        ];
    }
}
```

Register it and select `documented-custom` when creating a bot for a preview, and
add the equivalent Go implementation before relying on it at runtime. The test
suite resolves the same class through `StrategyRegistry`, so the documented
example is verified against the preview contract.


## Market history

The runtime keeps the last 30 tick prices in `bots.settings.recent_prices` and
passes them to the strategy as `MarketSnapshot::recentPrices`. The dynamic
strategy uses this window to measure volatility; without it, spacing falls back
to the configured initial spacing.

When a grid is recreated (for example after a trailing recenter), the pipeline
cancels the old orders and then re-evaluates the strategy so every level in the
new grid receives an order instead of leaving kept levels empty.
