# Tasks

## 1. Reconcile flag and schema

- [x] 1.1 Add a forward migration for `bots.reconcile_pending` (boolean, default false, indexed if the periodic query needs it), add it to `Bot` fillable/casts, and add a factory state; verify `php artisan migrate --force`, `migrate:rollback --step=1 --force`, and a model test.
- [x] 1.2 Add `ReconcilePending` to the Go `Bot` struct, load it in `loadBot`, and add a typed boolean payload helper for `reconcile`; verify `go test ./...` passes.

## 2. Diff, preview, and request validation

- [x] 2.1 Create `App\Domain\Bot\BotEditDiff` with a flat field map, labels/types, canonical decimal comparison, and empty-list no-op behavior; verify `BotEditDiffTest` covers changed/unchanged fields, nested strategy fields, decimal normalization, and ignored fields.
- [x] 2.2 Extend `PreviewBotRequest` with optional `name`/`fee_rate` and a `proposedEdit()` helper; compute the diff in `BotController::preview` before the plan call and return it as `diff`; verify `BotPreviewTest` covers diff output, diff on engine 503, and empty diff for create previews.
- [x] 2.3 Add `confirm_running_edit` to `UpdateBotRequest` as required only for a running paper bot and accepted when present; verify tests for missing confirmation, accepted confirmation, and non-running edits.
- [x] 2.4 Add the policy matrix to `BotPolicy::update` (paper running allowed; live running and paused denied) and expose an Edit link for running paper bots on `Bots/Show.vue`; verify policy tests and `BotEditUiTest` assertions.

## 3. Update flow and activity

- [x] 3.1 Update `BotController::edit` to pass `editable`, `requiresConfirmation`, `runningPaper`, `preview`, and an initial empty `diff`; verify page-prop tests for idle, running paper, live running, and paused.
- [x] 3.2 Rework `BotController::update` to recheck policy and confirmation under `lockForUpdate`, compute the diff, treat no-op saves as no persistence, and classify structural/capital changes from the diff; verify `BotUpdateTest` for success, missing confirmation, live running, paused, no-op, and TOCTOU.
- [x] 3.3 Set `reconcile_pending` and publish `{strategy_reset: true, reconcile: true}` for running paper structural/capital changes; keep `strategy_reset_pending`-only behavior for idle/stopped; record the diff and reconcile decision in `bot.updated`; verify publish payload, activity context, publish-failure response, and no reconcile for name/fee.
- [x] 3.4 Ensure a pending reconcile forces the full reconcile path even when the new edit is name/fee/no-op, and that no-op edits do not create a second pending state; verify the recovery and no-op tests.

## 4. Engine reconcile

- [x] 4.1 Implement an idempotent `reconcileRunningPaper()` under the bot lock: cancel open orders, reset strategy state, clear `reconcile_pending` and related flags; make cancellation injectable for tests; verify cancellation success, cancellation failure, and idempotency.
- [x] 4.2 Add the reconcile gate before strategy evaluation and inside `placeOrders`, so a pending or failed reconcile blocks new placement and does not update `last_successful_tick_at`; verify the tick-gate test.
- [x] 4.3 Complete pending reconcile on the start path and include `reconcile_pending`, `cancel_retry_requested`, and `strategy_reset_pending` in the periodic query; verify start, periodic recovery, stop/start safety, and live-ignore tests.
- [x] 4.4 Preserve existing non-reconcile `bot.config` behavior and ensure the periodic path never resets a live bot; verify the non-reconcile and live tests.

## 5. Edit UI and docs

- [x] 5.1 Add the running-paper banner, field diff, confirmation checkbox, `confirm_running_edit` form field/error, preview payload name/fee, and watchers for every editable field in `Bots/Edit.vue`; verify `npm run build` and `BotEditUiTest`.
- [x] 5.2 Render publish-failure `errors.engine` on `Bots/Show.vue`; verify the page renders the error and the existing action feedback remains.
- [x] 5.3 Update `docs/dashboard.md` for the policy matrix, running-paper diff/preview/confirmation, activity, and reconcile behavior; update engine/deployment docs for engine-first rollout and rollback; verify `DashboardDocsTest` and the docs fixture names.

## 6. Integration verification

- [x] 6.1 Run the full PHP suite with the documented Docker environment overrides; verify zero failures.
- [x] 6.2 Run PHPStan level 5 and Pint on changed PHP files; verify no errors and formatting passes.
- [x] 6.3 Run the Go test suite and the frontend build/JS tests; verify all pass.
- [x] 6.4 Run `openspec validate add-safe-running-bot-edits --strict`, `git diff --check`, and the live-safety grep; verify the change is valid, the diff is clean, and no live gate or `bots.start` path was added.

## Workflow follow-up

- Request code review after implementation and fix Critical/Important findings.
- Sync the `trading-dashboard` and `bot-runtime` specs, archive the change, commit, push, and open a PR linked to issue #17 item 6.
