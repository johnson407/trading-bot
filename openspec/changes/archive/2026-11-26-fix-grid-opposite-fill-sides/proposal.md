# Proposal

## Why

All four grid strategies label their post-fill order `strategy.opposite`, but they actually place the adjacent level's configured side. On outer levels that side is the same as the fill's side, so a buy fill creates another buy above the market. The shared re-arm helper also ignores fill side, so a gap through several levels re-arms already-filled buys. The result is order churn, repeated buys above market, and, on martingale, escalating size. This contradicts the existing `grid-trading` fill requirement and the classic spot-grid behavior users expect.

The live runtime fills a qualifying batch before it places post-fill orders, so a six-buy gap can place six sells. The backtest deliberately processes same-candle fills sequentially, so it can defer an opposite order while the adjacent level is still open. The plan must state both outcomes: the live runtime is the six-sell acceptance, and the deterministic backtest gets a sequential-candle equivalent.

## What Changes

- Place the side **opposite the fill** at the adjacent level in all four Go runtime strategies: simple, dynamic, martingale, and trailing.
- Make disarmed-level re-arming side-aware: a level is re-armed only when its paired price fills on the opposite side of the original fill.
- Martingale: size the adjacent take-profit order from the **filled quantity**, not the increased next-buy quantity, so a sell can never exceed the inventory actually bought. The increased size continues to apply to future buys.
- Mirror the same behavior in the four Laravel preview strategies and the shared grid-level trait.
- Add failing tests first: outer-level buy/sell fills, multiple same-side fills, side-aware re-arm, and martingale quantity coverage. The live-runtime acceptance is six buys producing six sells at 50, 60, 70, 80, 90, and 110 for a center of 100 and 10 spacing with no base inventory.
- Update the martingale on-fill expectations in `tests/golden/engine/strategies.json` only where the adjacent quantity changes. Update the two affected cases in `tests/golden/engine/backtest_replay.json`; do not edit `tests/golden/README.md`.
- Clarify the related specs: `strategy-engine` (armed levels and martingale take-profit quantity), `backtesting` (sequential same-candle replay may defer an opposite order), and `trading-dashboard` (show the actual order side at a level when it differs from the plan side).
- Add a small dashboard change: expose the open order's level and show the actual order side beside the plan side. This is observability only; it does not change trading behavior.
- No schema, route, or API changes. Grid level plans, one-open-order-per-level, paper-fill ordering, and all risk gates stay as they are.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `grid-trading`: the post-fill order is always opposite the fill, regardless of the adjacent level's configured side; define side-aware re-arm and the unfilled-pair limitation.
- `strategy-engine`: disarmed levels are skipped, and the martingale take-profit sell uses the filled quantity while future entries use the increased size.
- `backtesting`: document that sequential same-candle replay may defer an opposite order when its adjacent level is still open.
- `trading-dashboard`: show both the plan side and the actual order side when they differ.

## Impact

- Go runtime: `engine/internal/strategy/simple.go`, `dynamic.go`, `martingale.go`, `trailing.go`, and shared `grid.go`.
- Laravel preview: `app/Strategies/SimpleGridStrategy.php`, `DynamicGridStrategy.php`, `MartingaleGridStrategy.php`, `TrailingGridStrategy.php`, and `app/Strategies/Concerns/GeneratesGridLevels.php`.
- Tests: Go strategy and backtest tests, the opt-in runtime integration test, PHP strategy unit tests, `tests/golden/engine/strategies.json` martingale quantities, the two affected `tests/golden/engine/backtest_replay.json` cases, and `tests/Unit/MartingaleGridStrategyTest.php`.
- Dashboard: `app/Http/Controllers/BotController.php` and `resources/js/Pages/Bots/Show.vue`, plus a focused feature test.
- Backtest: `engine/internal/backtest/engine.go` already emits the correct opposite intent; the strategy intent now dedupes to the same order. Keep the sequential replay semantics and add the documented divergence.
- Docs: `engine/README.md` and `docs/bot-parameters.md` already describe opposite-side behavior; clarify that a level's plan side can differ from its current order side.
- The local demo seeder creates a 6-level grid while the runtime reads `level_count: 6` as six levels per side. This is an environment/demo-data issue, not part of this change; reset or reseed the demo bot before manual verification.
