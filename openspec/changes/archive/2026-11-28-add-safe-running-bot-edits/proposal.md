# Proposal

## Why

Operators can only edit a bot while it is idle or stopped. Once a paper bot is running, changing a level, spacing, capital allocation, or fee rate requires stopping the bot, editing it, and starting it again, which interrupts the strategy and loses the ability to review the exact impact before applying the change. GitHub issue #17 item 6 asks for safer bot edits: show what will change and preview the orders before saving.

## What Changes

- Allow the owner to edit a **paper** bot while it is running, after reviewing a server-computed field diff and the existing ladder/order preview and explicitly confirming the change.
- Keep live bots editable only while idle or stopped. Paused bots remain non-editable, preserving the current safety posture and the rule that nothing resumes automatically.
- Add a `BotEditDiff` diff builder and return the diff from the bot preview endpoint so the edit page can show changed fields with before/after values.
- Require an explicit `confirm_running_edit` acknowledgement for running paper updates; reject missing confirmation and reject running live updates.
- When a running paper edit changes structural strategy settings or capital allocation, set a dedicated `reconcile_pending` flag and publish a `bot.config` command with a reconcile flag. The engine cancels open orders, resets strategy state, clears the pending flag, and lets the next tick place the new ladder. Non-structural changes such as name or fee rate apply without a reconcile.
- Block order placement while a reconcile is pending, including after a failed publish or cancellation. The engine retries through the command path and the periodic reconcile path.
- Add one forward migration for `bots.reconcile_pending`; no backfill. Engine-first deployment is required so the tick gate exists before the app can set the flag.
- Record the field diff and reconcile decision in `bot.updated` activity context.
- Update the dashboard documentation and specs.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `trading-dashboard`: Bot editing now allows running paper bots with a diff, ladder preview, and explicit confirmation; live running and paused bots remain blocked.
- `bot-runtime`: Running paper configuration changes require a reconcile that cancels open orders and resets strategy state before the next tick.

## Impact

- Policies and HTTP: `BotPolicy`, `BotController::edit`, `BotController::update`, `BotController::preview`, `UpdateBotRequest`, `PreviewBotRequest`.
- Domain: new `App\Domain\Bot\BotEditDiff`; activity context in `BotController::update`.
- Engine: `reconcile_pending` bot field and migration; `bot.config` handling in `engine/internal/runtime/runtime.go`; tick placement gate; protocol payload parsing; runtime tests.
- UI: `resources/js/Pages/Bots/Edit.vue`, `PlanPreview.vue` reuse, diff rendering, confirmation control; `Show.vue` edit entry point and engine-error display.
- Docs and tests: `docs/dashboard.md`, `DashboardDocsTest`, `BotUpdateTest`, `BotPreviewTest`, new diff/UI tests, Go runtime tests.
- Deployment: one forward migration; engine-first rollout. No new environment variable and no live-gate, credential, balance, kill-switch, or auto-resume change.
