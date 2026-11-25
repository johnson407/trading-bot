# Proposal

## Why

The primary navigation currently mixes trading destinations with destructive controls and account configuration. The kill switch is an operational bot control, but it sits beside routine links, while exchange credentials and risk limits are account settings that clutter the same menu. This change separates those concerns so the kill switch is harder to trigger accidentally and account configuration lives in one predictable place.

## What Changes

- Add a `/settings` hub (`settings.index`) that links to exchange credentials and risk limits.
- Move exchange-credential management from `/exchange-credentials` to `/settings/credentials`, keeping the existing resource route names and CRUD pages.
- Move account risk limits from `/risk-limits` to `/settings/risk-limits`, keeping the existing route names and form behavior.
- Replace the desktop and responsive primary-navigation items **Credentials**, **Risk limits**, and **Kill switch** with a single **Settings** link.
- Add a danger-styled **Kill switch** button next to **Create bot** on the bot list. The existing `/kill-switch` confirmation page, exact phrase check, and dashboard quick link remain unchanged.
- Update the dashboard and risk documentation and the navigation, settings, and docs tests to match the new placement.

## Capabilities

### New Capabilities

None. The settings hub and navigation changes belong to the existing dashboard capability.

### Modified Capabilities

- `trading-dashboard`: the kill-switch entry point moves from primary navigation to the bot list; exchange credentials and risk limits move under a Settings hub; primary navigation now links to Settings instead of those individual pages.
- `risk-controls`: account risk-limit configuration remains available to the user, now through the Settings area.
- `app-visual-theme`: the kill-switch identification scenario now refers to the bot list where the danger-styled control is presented.

## Impact

Affected code and configuration:

- `routes/web.php`: add the settings route, move the credentials resource and risk-limit routes under `/settings`, and preserve existing route names.
- `app/Http/Controllers/SettingsController.php`: new controller for the settings hub.
- `resources/js/Pages/Settings/Index.vue`: new hub page linking to credentials and risk limits.
- `resources/js/Layouts/AuthenticatedLayout.vue`: replace the three navigation items with Settings in desktop and responsive menus.
- `resources/js/Pages/Bots/Index.vue`: add the danger-styled kill-switch link beside Create bot.
- `docs/dashboard.md` and `docs/risk.md`: update URLs and entry-point descriptions.
- `tests/Feature/DashboardNavigationTest.php`, `tests/Unit/DashboardDocsTest.php`, and a new settings/navigation feature test.
- Existing credential and risk-limit controller, model, authorization, safety, and confirmation behavior is unchanged.
