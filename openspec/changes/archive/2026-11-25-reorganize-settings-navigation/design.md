# Design

## Context

See `proposal.md` for motivation. The current authenticated layout renders primary navigation items for **Credentials** (`exchange-credentials.index`), **Risk limits** (`risk-limits.edit`), and **Kill switch** (`kill-switch.create`) in both desktop and responsive menus. The routes live at `/exchange-credentials` and `/risk-limits`, and the bot list header renders only **Create bot**.

The existing credential and risk-limit controllers, authorization policies, validation, flash messages, and kill-switch confirmation flow are already correct. This change is limited to routing, page composition, navigation, documentation, and their tests.

## Goals / Non-Goals

**Goals:**

- Group exchange credentials and risk limits under a Settings hub without changing their CRUD or validation behavior.
- Make the kill switch an explicit bot-list action with the existing confirmation flow.
- Keep every existing named route available so controllers, tests, docs, and Inertia links continue to resolve.
- Keep the change reversible without database migrations.

**Non-Goals:**

- Changing credential encryption, masking, ownership, or validation.
- Changing risk-limit enforcement, defaults, or update semantics.
- Changing kill-switch activation, ordering, failure reporting, or the exact confirmation phrase.
- Adding a general preferences/settings model or new user-editable settings.
- Adding compatibility redirects from the old URLs.

## Decisions

### Keep route names, move URIs under `/settings`

The credentials resource and risk-limit routes move under a `settings` URI prefix, but keep the names `exchange-credentials.*` and `risk-limits.*`. This limits churn: controllers redirect with the same names, Ziggy links continue to work, and `DashboardDocsTest` can keep documenting the same route names. Renaming to `settings.credentials.*` and `settings.risk-limits.*` was rejected because it would touch every reference for no user-visible benefit.

New route surface:

| URI | Route name | Component |
| --- | --- | --- |
| `GET /settings` | `settings.index` | `Settings/Index` |
| `/settings/credentials` (resource, no `show`) | `exchange-credentials.*` | `ExchangeCredentials/*` |
| `GET /settings/risk-limits` | `risk-limits.edit` | `RiskLimits/Edit` |
| `PATCH /settings/risk-limits` | `risk-limits.update` | redirect to `risk-limits.edit` |
| `/kill-switch` (unchanged) | `kill-switch.*` | `KillSwitch/Confirm` |

Use a `Route::prefix('settings')` group. Register the credential resource with `parameters(['credentials' => 'exchangeCredential'])` so the existing `ExchangeCredential $exchangeCredential` controller signatures bind correctly, and keep the existing `throttle:exchange-credentials` middleware.

### Settings hub as a link-based page

Add `SettingsController@index`, which renders `Settings/Index`. The page shows two cards linking to exchange credentials and risk limits, with short descriptions. A combined single page or shared tab bar was rejected: it would duplicate the existing credential table and risk form or require a new layout abstraction for only two destinations. The existing pages remain directly reachable and keep their current headers.

### Kill switch in the bot-list header

On `Bots/Index.vue`, wrap the header actions in a flex container with a gap. Render **Create bot** as today and add a **Kill switch** link to `kill-switch.create` styled with the same rose danger tokens used by the existing danger navigation links (`border-rose-400`, `bg-rose-950`, `text-rose-200`, hover `bg-rose-900`). Use `flex-wrap` so the two controls remain usable on narrow screens.

Remove the kill-switch, credentials, and risk-limit items from both navigation menus. Add a single **Settings** `NavLink`/`ResponsiveNavLink` pointing at `settings.index`, active on `settings.*`, `exchange-credentials.*`, or `risk-limits.*`. Keep Alerts and its unread badge unchanged, and keep the dashboard's existing kill-switch and credentials quick links because they route through the named routes and remain valid.

### Documentation and tests follow the visible behavior

Update `docs/dashboard.md` URLs and entry-point text, and change `docs/risk.md` to point at **Settings > Risk limits**. Extend `DashboardNavigationTest` to assert the new URLs, the presence of the Settings link in both menus, the absence of the three former navigation items, and the bot-list kill-switch button. Add a focused feature test for the settings hub and the relocated pages, and update `DashboardDocsTest` to require the settings route and its feature-test name.

## Risks / Trade-offs

- **Old URLs break** → Internal links use route names, so the application remains consistent. No redirects are added because bookmarks are not part of the contract and the app is single-user/local; this is recorded in the migration plan.
- **Route model binding breaks after moving the resource** → Explicitly set the resource parameter to `exchangeCredential` and cover edit/update/destroy with the existing `ExchangeCredentialUiTest`, which continues to use named routes.
- **Bot-list header crowds on small screens** → Use `flex-wrap` and compact button padding; the danger button uses the same compact header sizing as Create bot.
- **Tests assert layout source text** → Update the string-based navigation assertions in the same change; do not weaken them into untested behavior.
- **Kill switch becomes less globally visible** → It remains a labeled, danger-styled control on the bot list and a quick link on the dashboard; the confirmation page and exact phrase gate are unchanged.
- **Docs drift from routes** → `DashboardDocsTest` and `DashboardNavigationTest` are updated and run in the same change.

## Migration Plan

1. Deploy the code change. No database migration or data backfill is required.
2. Internal links resolve through named routes to the new URLs; the old `/exchange-credentials` and `/risk-limits` URLs stop resolving.
3. Rollback is a code revert. Credentials, risk limits, bots, and orders are unaffected.
