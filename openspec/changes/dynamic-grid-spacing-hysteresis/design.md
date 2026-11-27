# Design

## Context

See `proposal.md` for the measured churn. The relevant current behavior is:

- `DynamicGrid.OnTick` recalculates spacing after the configured interval, writes `spacing_value`, and emits levels for the new spacing.
- `DynamicGrid.OnFill` and `GetGridLevels` reuse the persisted `spacing_value`.
- Runtime and backtest grid synchronization compare levels exactly. Any spacing change moves every level price, so the old ladder is canceled and a new one is created.
- Dynamic configuration is stored as JSON in `strategy_configs.configuration`; Laravel validates and builds it, and the Vue forms expose the shared dynamic fields.

There is no database column to migrate.

## Goals / Non-Goals

**Goals:**

- Keep the current ladder when a due volatility recalculation produces a spacing change below a configurable threshold.
- Preserve the existing interval guard: a skipped recalculation still waits for the next interval.
- Make the threshold visible and validated through the same configuration path as the other dynamic fields.
- Make the documented dynamic-grid flow match runtime behavior.

**Non-Goals:**

- Changing the frozen center price or adding recentering.
- Implementing selective per-order retention when spacing changes beyond the threshold.
- Changing grid synchronization, order placement, risk rules, or live gates.
- Adding new telemetry fields; existing tick counters are sufficient to measure the effect.
- Exposing the new parameter through backtest sweeps; backtests use the bot's stored value.

## Decisions

### Configuration key and default

Add `spacing_hysteresis_percent` as a decimal string.

- Default: `20`.
- Valid range: `0` through `90`, inclusive.
- Value is a percentage: `20` means 20 percent, not `0.20`.
- `0` disables hysteresis and preserves the old always-adopt behavior.

A 20 percent default is a deliberate balance. The measured bot-5 spacing sequence produced 14 replacements with no hysteresis, 10 with 10-15 percent, 6 with 20-30 percent, and 4 with 40 percent. Twenty percent roughly halves replacements while still allowing meaningful volatility changes to reprice the ladder.

Alternative considered: default `0` for strict backward compatibility. Rejected because it would ship the field without changing the measured behavior; operators could opt in, but the issue's churn would remain the default. The field is configurable, and `0` is the documented rollback lever.

The threshold SHALL be parsed and validated in a shared helper called by both `Initialize` and `OnTick` before the interval guard. This makes a preview with an invalid value fail immediately instead of allowing a bot to initialize and fail later.

### Hysteresis rule

After the normal spacing calculation and clamping:

```text
current   = persisted spacing_value; must be a positive decimal
candidate = clamp(center * volatility * multiplier, min_spacing, max_spacing)
threshold = configured spacing_hysteresis_percent (default 20)

if threshold == 0:
    adopt candidate
else if abs(candidate - current) * 100 >= threshold * current:
    adopt candidate
else:
    keep current
```

Use exact `decimal.Decimal` arithmetic, not floats. Compare by cross-multiplication rather than dividing by `current`, so a fractional threshold at the exact boundary cannot round below the threshold and skip adoption. The boundary is inclusive: a candidate exactly at the threshold is adopted.

The current spacing is read with `decimal.Of(input.State.Get("spacing_value", nil))` and must also be positive. If it is missing, non-numeric, zero, or negative, return an error. Do not adopt the candidate as a recovery path: `GetGridLevels` already fails on missing or invalid spacing before the due branch, and the state-persistence requirement says an uninterpretable state pauses the bot. For live bots, silently repricing from corrupted state could cancel and replace orders, so recovery is not safe here.

Keeping the current spacing regenerates exactly the same levels, so exact grid matching succeeds and no orders are canceled.

When hysteresis skips a change, the tick merges the same `spacing_value` into state, advances `last_recalculated_at`, and increments `recalculation_count`. It returns the current levels and no cancel intents. This prevents every subsequent tick from re-evaluating the same candidate and preserves the configured interval cadence.

Alternative considered: compare generated order prices instead of spacing percentages. Rejected because a percentage threshold is easier to reason about and tune, and a price-level comparison would still need an arbitrary tolerance.

### Validation and configuration flow

- Add the field to `StoreBotRequest`, `UpdateBotRequest`, and `PreviewBotRequest` as nullable, numeric, minimum `0`, maximum `90`.
- Add it to `BuildsStrategyConfiguration` for `dynamic-grid`; store the decimal string, defaulting to `20`.
- Add it to `PreviewBotRequest::concreteValues()` so the quick-setup preview response echoes the value. `concreteValues()` is the derived-values response, not the validation path; validation comes from the request rules and `buildStrategyConfiguration`.
- Add an explicit `spacing_hysteresis_percent` case to `engine/internal/plan/plan.go` `fieldFor` before the generic `spacing` case, and wrap non-numeric parse errors with the field name. Otherwise a Go validation error would be reported against `spacing_value`.
- Add it to `botSetup.js`'s advanced preview payload, the Create/Edit preview watchers, and the `isDynamic` form blocks with default `20` and a numeric input.
- Keep the field visible only for `dynamic-grid`, alongside the existing dynamic fields.

### Documentation correction

`docs/bot-parameters.md` currently says a dynamic recalculation cancels orders not at the new prices and keeps matching ones. Runtime does not do that: a spacing change beyond the threshold replaces the whole ladder. Update the flow to say:

- a change within the threshold keeps the current ladder;
- a change at or above the threshold replaces the ladder and places orders at the new levels;
- the center remains fixed.

The same document makes a matching selective-retention claim for `trailing-grid`; runtime also replaces the full ladder on a recenter. Correct that wording in this change as a documentation-only fix.

Also document that `20` means 20 percent, that clamping applies to the candidate, and that a high threshold can retain spacing away from the configured bounds for long periods. Recommend values near the default.

The dynamic strategy's unused `CancelClientOrderIDs` path is left unchanged. Removing it is a separate cleanup; this change only makes the documented behavior truthful.

### Testing strategy

- Go table tests cover: candidate within threshold retains spacing/levels; candidate exactly at threshold adopts; candidate above and below threshold; fractional threshold with decimal values; zero threshold always adopts; default is 20; invalid configuration returns a field-specific error; missing, non-numeric, zero, and negative current spacing fail closed.
- Add a deterministic no-replacement test: a due recalculation below the threshold returns identical levels and no cancel intents, and a backtest or runtime-sync assertion confirms no grid replacement or order cancellation.
- Use explicit `spacing_hysteresis_percent: 0` in existing dynamic tests and golden fixtures that assert the old always-adopt sequence. Check `tests/golden/engine/backtest_replay.json` case `dynamic_grid_recalculates` and the dynamic cases in `tests/golden/engine/strategies.json`; add new cases for default and threshold behavior.
- Laravel tests cover create/update/preview validation, default storage, inclusive bounds, rejection, and an invalid value failing preview before initialization.
- Add `tests/Unit/StrategyDocsTest.php` asserting the documented key, default `20`, range `0`-`90`, `0` rollback lever, and corrected full-ladder flow. The existing docs tests do not read `docs/strategies.md` or `docs/bot-parameters.md`.
- After deployment, compare tick telemetry (`grids_replaced`, `orders_canceled`, `orders_placed`, `paper_fills`) for the dynamic bots over a comparable window.

## Risks / Trade-offs

- **Existing bots change behavior by default** → Document the 20 percent default and the `0` rollback lever in the parameter docs; operators can tune per bot.
- **First update of an existing bot is a structural change** → Adding the key changes the configuration array, so `BotController` marks `strategy_reset_pending` and publishes `bot.config` even for a rename. Bots are editable only while idle or stopped, so the risk is limited, but the migration notes must call it out. Omitting the field on update stores the default `20`.
- **Spacing can lag a genuine volatility regime change by up to 20 percent** → Larger changes still adopt immediately; the threshold is capped at 90 and configurable per bot.
- **A very high threshold can freeze the ladder for long periods** → Validation allows at most 90; docs explain the trade-off and recommend values near the default.
- **Invalid persisted state** → Missing, non-numeric, zero, or negative current spacing fails the tick under the existing state-persistence rules; the bot does not silently reprice from corrupted state.
- **Docs and behavior could drift again** → The change updates the documented flow and adds a docs assertion for the parameter.

## Migration Plan

1. Deploy the engine and Laravel changes together; no database migration is required.
2. Existing dynamic bots without the key use 20 percent on their next recalculation.
3. The first save of an existing dynamic bot adds the key and counts as a structural configuration change; the bot must be idle or stopped, and the engine resets strategy state before the next run.
4. To preserve the old behavior for a specific bot, set `spacing_hysteresis_percent` to `0` and save the bot.
5. Roll back by reverting the images. Stored configurations containing the extra key remain readable; older code ignores the unknown configuration field.

## Open Questions

None.
