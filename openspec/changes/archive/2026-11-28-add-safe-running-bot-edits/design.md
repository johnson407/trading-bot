# Design

## Context

See `proposal.md` for the motivation and `specs/trading-dashboard/spec.md` and `specs/bot-runtime/spec.md` for the behavior contract.

Today `BotPolicy::update()` and `BotController::update()` allow edits only for idle or stopped bots. The edit page already computes a ladder preview through `bots.preview`, but it hides the form for any other state and has no field-level diff or confirmation step.

A structural configuration change already sets `strategy_reset_pending` and publishes `bot.config` with `strategy_reset: true`. The engine's `bot.config` handler resets strategy state on the next command, but it does not cancel open orders. The existing `cancel_retry_requested` flag and periodic reconcile path provide a durable retry for order cancellation, but neither flag currently gates order placement.

Reusing those two flags for running-paper edits creates ambiguous states: a name-only save after a failed structural edit can reset before cancelling, stop/start can leave a stale cancellation flag, and the periodic path can reset a live bot. This design therefore adds one dedicated `reconcile_pending` flag for the new path and keeps the existing flags for their current jobs.

## Goals / Non-Goals

**Goals:**

- Let an owner edit a running paper bot with a visible field diff, the existing ladder/order preview, and explicit confirmation.
- Make the saved change reproduce exactly what was previewed.
- Cancel open orders and reset strategy state when a running paper edit changes strategy structure or capital allocation.
- Block new placement while a reconcile is pending, including after command-publish or cancellation failure.
- Keep live running bots and all paused bots non-editable.
- Make stop/start, command/periodic races, and rollout behavior explicit.

**Non-Goals:**

- Editing live bots while running, or auto-resuming a paused bot.
- Changing the live gate, credential checks, balance checks, kill switch, risk limits, or protective-exit editing.
- Adding a new grid-reset or order-preview endpoint; reuse `bots.preview` and `PlanPreview`.
- Persisting an edit history beyond the existing activity log.
- A general-purpose engine configuration state machine beyond the running-paper reconcile path.

## Decisions

### 1. Policy matrix

| Bot state | Paper | Live |
|---|---|---|
| Idle | Editable | Editable |
| Stopped | Editable | Editable |
| Running | Editable with confirmation + diff + preview | Not editable |
| Paused | Not editable | Not editable |
| Error | Not editable | Not editable |

`BotPolicy::update()` becomes owner + the matrix above. `BotController::update()` rechecks the same matrix inside the existing `lockForUpdate` transaction. The check is duplicated deliberately so a future caller cannot bypass the policy.

**Alternative considered:** allow paused bots to be edited but not resumed. Rejected because a paused bot may be paused by risk or the engine, and editing it would make the paused state ambiguous. The existing rule that nothing resumes automatically stays intact.

### 2. Dedicated reconcile flag

Add one nullable-free boolean column:

```php
$table->boolean('reconcile_pending')->default(false);
```

`Bot` gains a `boolean` cast and PHPDoc. The Go `Bot` struct and `loadBot` gain `ReconcilePending`. No backfill is needed; existing bots default to false.

Why not reuse the existing flags:

- `strategy_reset_pending` is also used by idle/stopped structural edits and the engine's start path.
- `cancel_retry_requested` is also used by unrelated cancellation failures.
- A dedicated flag lets the tick gate, start path, periodic path, and command path all use one unambiguous condition.
- It lets a running-paper reconcile avoid setting `strategy_reset_pending`, so live bots and existing strategy-reset behavior are not accidentally pulled into the new path.

### 3. Preview returns a field diff

Add `App\Domain\Bot\BotEditDiff`:

```php
BotEditDiff::between(Bot $bot, array $proposed): array
```

`$proposed` contains `name`, `capital_allocation`, `fee_rate`, and `configuration` (the same flat array produced by `BuildsStrategyConfiguration`). The diff compares canonical decimal/string values and returns a list of changed rows:

```php
[
    'key' => 'strategy.center_price',
    'label' => 'Center price',
    'type' => 'money', // text|money|quantity|rate|number|integer|enum
    'from' => '50000',
    'to' => '51000',
]
```

The editable field map is:

| Key | Label | Type |
|---|---|---|
| `name` | Name | text |
| `capital_allocation` | Capital allocation | money |
| `fee_rate` | Fee rate | rate |
| `strategy.center_price` | Center price | money |
| `strategy.levels_per_side` | Levels per side | integer |
| `strategy.spacing_type` | Spacing type | enum |
| `strategy.spacing_value` | Spacing value | money |
| `strategy.order_quantity` | Order quantity | quantity |
| `strategy.trailing_distance` | Trailing distance | money |
| `strategy.base_order_quantity` | Base order quantity | quantity |
| `strategy.size_multiplier` | Size multiplier | number |
| `strategy.max_order_quantity` | Max order quantity | quantity |
| `strategy.max_increases` | Max increases | integer |
| `strategy.min_spacing` | Min spacing | money |
| `strategy.max_spacing` | Max spacing | money |
| `strategy.volatility_multiplier` | Volatility multiplier | number |
| `strategy.recalculation_interval_seconds` | Recalculation interval | integer |
| `strategy.spacing_hysteresis_percent` | Spacing hysteresis | rate |

The diff ignores engine-owned settings, state, timestamps, and identity fields. Unknown/legacy strategy keys are not shown and are replaced by the normal full-configuration save, as they are today; the design does not add key preservation.

`PreviewBotRequest` gains optional `name` and `fee_rate` rules and a `proposedEdit()` helper that defaults to the bot's current values. `BotController::preview()` returns `diff` alongside the existing plan, errors, warnings, limits, market, and values. Create previews return an empty diff because there is no bot to compare. The diff is computed before the plan-evaluator call so a 503 from the engine does not hide the changed fields.

`Edit.vue` posts name and fee in its preview payload, renders the diff above the ladder preview, and shows “No changes” when the list is empty. The save button stays disabled until the user confirms when the bot is running paper.

### 4. Confirmation and status recheck

`UpdateBotRequest` gains:

```php
'confirm_running_edit' => ['nullable', Rule::requiredIf($this->requiresRunningConfirmation()), 'accepted'],
```

`requiresRunningConfirmation()` is true only when the route bot is paper and currently running. A missing or false value returns a validation error on `confirm_running_edit`.

The request check is early feedback only. `BotController::update()` must recheck inside the `lockForUpdate` transaction using the locked bot: if it is paper + running and the request lacks confirmation, abort with a `confirm_running_edit` validation error before any write. The diff and reconcile classification are computed from the locked row, not the route model. This closes the status-change TOCTOU.

`Edit.vue` shows a running-paper banner:

> This bot is running. Saving changes will cancel open orders and rebuild the ladder on the next tick.

The confirmation checkbox is explicit: “I understand open orders will be cancelled and the ladder rebuilt.” `confirm_running_edit` is part of `useForm` and is rendered with `form.errors.confirm_running_edit`.

### 5. Update classification and flags

`BotController::update()` computes `BotEditDiff` and classifies from the diff, not from a raw `!==` array comparison:

- Empty diff: no update, no activity, no command.
- `name` and `fee_rate` only: update the bot, no reconcile, no `bot.config` command.
- `capital_allocation` changed: structural for this purpose.
- Any `strategy.*` key changed: structural.
- Structural change on an idle/stopped bot: set `strategy_reset_pending = true` as today; no `reconcile_pending` and no cancellation. The next start resets strategy state.
- Structural change or capital change on a running paper bot: set `reconcile_pending = true`; do not set `strategy_reset_pending`; publish `bot.config` with `{"strategy_reset": true, "reconcile": true}`.
- Running paper bot with `reconcile_pending` already true: force the reconcile path even for a name-only, fee-only, or no-op save. Do not publish a bare `strategy_reset` while a reconcile is pending.
- No structural change on a running paper bot and no pending reconcile: update only; no command.

The activity context records the diff, `strategy_reset` (true for idle/stopped structural changes and running-paper reconciles), and `reconcile`.

If publish fails, `reconcile_pending` stays true and the response reports that the change is saved but the engine has not confirmed it yet. The periodic path retries. The controller redirects to the bot detail page with `errors.engine`, and `Show.vue` renders it. The message must not suggest a no-op retry; it should say the engine will retry the reconcile.

### 6. Engine state machine

The new path is driven by `reconcile_pending`:

| State | Command | Tick | Start | Periodic |
|---|---|---|---|---|
| Paper running, pending | cancel → reset → clear | cancel → reset → clear; no placement on failure | n/a | cancel → reset → clear |
| Paper stopped or idle, pending | leave pending | n/a | cancel → reset → clear before running; refuse on failure | cancel → reset → clear |
| Live, pending | log and clear, no cancel/reset | log and skip placement; do not reset | log and skip; do not reset | log and clear, no cancel/reset |
| No pending | existing `strategy_reset` behavior | normal tick | existing start behavior | existing cancel/strategy-reset behavior |

The new path never sets `strategy_reset_pending`, so a live bot cannot enter it through a running-paper edit. Existing `strategy_reset` behavior for live idle/stopped configuration changes is unchanged.

`reconcileRunningPaper()` is the single implementation:

1. Under the existing bot lock, re-read the bot and return if `reconcile_pending` is false (idempotency).
2. If the bot is live, log the invariant violation, clear `reconcile_pending`, and return without cancelling or resetting.
3. Cancel open orders. If cancellation fails, keep `reconcile_pending` true and leave `cancel_retry_requested` set; do not reset strategy state; return the error.
4. Reset strategy state.
5. Clear `reconcile_pending` and any `cancel_retry_requested` set by this reconcile.
6. Record a reconcile success/failure activity or log entry for observability.

`bot.config` handling:

- `reconcile: true` calls `reconcileRunningPaper()` and does not call the old `resetStrategyState()` directly.
- `reconcile: false` preserves the existing `strategy_reset` behavior.
- Add a small protocol helper for boolean payload fields instead of ad-hoc assertions.

`tickBot()` gate:

- After `loadBot` and before loading the strategy, if `ReconcilePending` is true, call `reconcileRunningPaper()`.
- If it fails, return before strategy evaluation and before `placeOrders()`. Do not update `last_successful_tick_at`; the tick is not a success.
- If it succeeds, continue the tick with the new configuration.
- The gate must also apply to the `placeOrders()` transaction as defense in depth: re-read `reconcile_pending` under the existing `FOR UPDATE` and return without placing if it is still true.

`applyStatus` start:

- If the bot is paper and `ReconcilePending` is true, call `reconcileRunningPaper()` before setting `running`.
- If reconcile fails, do not set `running`; leave the bot stopped, set `last_error`, and record the failure. The operator can retry the start.
- This covers stop/start between the edit and the command and prevents a later periodic cancel from removing the newly placed ladder.

Periodic path:

- `reconcile()` selects bots with `reconcile_pending = true`, `cancel_retry_requested = true`, or `strategy_reset_pending = true`.
- If `reconcile_pending` is true, run `reconcileRunningPaper()`.
- Otherwise preserve the existing cancel-retry and strategy-reset behavior, with cancellation before reset when both are present.
- The command and periodic paths both call the same idempotent `reconcileRunningPaper()`, so whichever runs second sees the cleared flag and does nothing.

### 7. UI state and copy

`BotController::edit()` passes:

- `editable`: policy result, not a raw status check.
- `requiresConfirmation`: paper running.
- `runningPaper`: paper running, for the banner and checkbox.
- `preview`: the current plan.
- `diff`: empty on initial load; the page gets it from preview responses.

For live running, paused, and error bots, keep the form hidden and show state-specific copy:

- Live running: “Stop the live bot before editing its settings.”
- Paused: “Paused bots cannot be edited. Resume or stop the bot before changing its settings.”
- Error: “Resolve the bot error before editing its settings.”

`Show.vue` includes the edit action for idle, stopped, and running paper bots. Non-editable states do not show an active edit link. `Show.vue` renders `errors.engine` so a publish failure is visible.

`Edit.vue` watches every editable field that affects the preview or diff: name, capital, fee, center, levels, spacing type/value, order quantity, trailing distance, size multiplier, max order quantity, max increases, min/max spacing, volatility multiplier, and recalculation interval. The confirmation checkbox is part of the form and is rendered with its validation error.

### 8. Documentation and tests

Update `docs/dashboard.md` for the policy matrix, running-paper banner, diff, preview, confirmation, activity context, engine reconcile behavior, and engine-error surface. Keep `DashboardDocsTest` route and feature-test names in sync.

PHP tests:

- `BotEditDiffTest` for changed/unchanged fields, decimal normalization, flat strategy keys, ignored fields, and removed known keys.
- `BotUpdateTest` for paper running success with confirmation, missing confirmation, live running rejection, paused rejection, status-change TOCTOU, structural reconcile flag/payload, capital-only reconcile, name/fee no reconcile, no-op no command, activity context, publish failure, and the updated running-live 403 expectation.
- `BotPreviewTest` for diff in the preview response, create previews with an empty diff, and diff available when the plan evaluator returns 503.
- `BotEditUiTest` for the Show.vue edit link, watcher fields, confirmation checkbox, “No changes” state, and engine-error display.

Go tests:

- `bot.config` with reconcile cancels open orders, resets state, and clears `reconcile_pending`.
- Cancellation failure keeps the pending flag and a subsequent tick places zero orders.
- Periodic reconcile completes the same flow after a publish failure.
- Live reconcile is ignored and does not reset.
- Non-reconcile config changes preserve existing behavior.
- Start with pending reconcile completes before running, or refuses on failure.
- Stop/start does not cancel the new ladder.
- Command/periodic idempotency does not double-cancel.

The paper cancellation path is local database work. Add a small injectable cancellation function on the runtime for tests so a failed-cancel test can assert the tick gate without closing the pool or cancelling the test context.

### 9. Deployment and rollback

Engine-first deployment is required:

1. Apply the `reconcile_pending` migration.
2. Deploy the engine with the field, tick gate, start handling, and periodic path.
3. Deploy the application with the policy, diff, confirmation, and command changes.

App-first is unsafe: an old engine does not gate ticks on `reconcile_pending` and could place the new ladder before cancelling old orders. Do not claim app-first safety.

Rollback:

- Roll back the app first. The engine can still complete any pending reconcile and ignores new commands.
- Clear any `reconcile_pending` flags before rolling back the engine, or keep the new engine running. An old engine does not understand the new gate.
- No schema rollback is required; the column can remain in place while the app and engine are reverted.

## Risks / Trade-offs

- **Temporary order gap** → The running paper edit cancels open orders before rebuilding. The preview and explicit confirmation make this visible; paper only.
- **Cancellation failure leaves the bot without new orders** → This is deliberate: `reconcile_pending` stays set and the tick gate blocks placement until cancellation succeeds. A retry loop is safer than placing orders against stale inventory.
- **Race with an in-flight tick** → The existing bot lock serializes the config command and tick. The tick gate rechecks the flag before strategy evaluation and again inside `placeOrders`.
- **Publish failure** → `reconcile_pending` remains and the periodic path retries; the detail page shows `errors.engine`.
- **Stop/start** → The start path completes the pending reconcile before running; if it cannot, it refuses the start.
- **Command/periodic race** → Both paths call the idempotent `reconcileRunningPaper()` and recheck the flag under the lock.
- **Diff and saved values diverge** → The diff and save use the same request-derived configuration and canonical comparison; tests cover both paths.
- **Live bypass** → Policy and engine both reject running live edits and reconcile; no live gate or confirmation path is touched.
- **Old engine during rollout** → Engine-first deployment is mandatory; app-first is explicitly unsafe.

## Migration Plan

One forward migration adds `bots.reconcile_pending` with a false default. No backfill. The migration is safe to run while the old app and engine are active; neither reads the column.

Deployment order: migration, engine, app. Rollback order: app, then engine after clearing pending flags. The column can remain.

## Open Questions

None.
