# Tasks

## 1. Settings routes and hub page

- [x] 1.1 Add `SettingsController@index` and a `settings` route group. Register `GET /settings` as `settings.index`, move risk limits to `GET|PATCH /settings/risk-limits` with the existing `risk-limits.edit`/`risk-limits.update` names, and move the credential resource to `/settings/credentials` with `names('exchange-credentials')` and `parameters(['credentials' => 'exchangeCredential'])`. Verify: `docker compose exec -u www-data app php artisan route:list --name=settings` and `--name=exchange-credentials` show the new URIs and preserved names.
- [x] 1.2 Create `resources/js/Pages/Settings/Index.vue` with authenticated-layout header, a card linking to `exchange-credentials.index`, and a card linking to `risk-limits.edit`. Verify: `docker compose exec -u www-data app npm run build` succeeds and the component is present in the build.
- [x] 1.3 Add `tests/Feature/SettingsUiTest.php` covering the settings hub component, the credential list at `/settings/credentials`, the risk-limit form at `/settings/risk-limits`, and an unauthenticated redirect. Verify: the focused test passes with the documented test-environment overrides.

## 2. Navigation and kill-switch placement

- [x] 2.1 Update `AuthenticatedLayout.vue` desktop and responsive menus: remove Credentials, Risk limits, and Kill switch; add Settings linking to `settings.index` and active for `settings.*`, `exchange-credentials.*`, and `risk-limits.*`. Keep Alerts and its unread badge. Verify: no `kill-switch.create`, `exchange-credentials.index`, or `risk-limits.edit` string remains in the layout, and `settings.index` appears in both menus.
- [x] 2.2 Update `Bots/Index.vue` to place a danger-styled Kill switch link beside Create bot, with a wrapping flex header and a visible gap. Verify: the bot list renders both controls and the kill-switch link targets `kill-switch.create`.
- [x] 2.3 Update `tests/Feature/DashboardNavigationTest.php` for the new URLs (`/settings`, `/settings/credentials`, `/settings/risk-limits`), the Settings navigation link, the absence of the three former items, and the bot-list kill-switch placement. Verify: the focused navigation test passes.

## 3. Documentation

- [x] 3.1 Update `docs/dashboard.md`: document `settings.index`, change the risk-limit and exchange-credential URLs, describe the Settings hub, update the kill-switch entry point, and add the new feature test to the test map. Verify: `DashboardDocsTest` passes with `settings.index` and `SettingsUiTest` included in its assertions.
- [x] 3.2 Update `docs/risk.md` to direct users to **Settings > Risk limits** instead of the navigation. Verify: `RiskDocsTest` still passes and the documented route name remains `risk-limits.edit`.

## 4. Integration verification

- [x] 4.1 Run the full PHP suite with the documented environment overrides and confirm all tests pass, including credential, risk-limit, kill-switch, navigation, and docs tests.
- [x] 4.2 Run PHPStan and Pint (`vendor/bin/phpstan analyse --memory-limit=1G --no-progress` and `vendor/bin/pint --dirty`); confirm no new errors and no formatting changes remain.
- [x] 4.3 Run `npm run build` and `npm run test:js`; confirm the frontend builds and JavaScript tests pass.
- [x] 4.4 Smoke-check the rendered routes: `/settings`, `/settings/credentials`, `/settings/risk-limits`, and `/bots` all respond for an authenticated user, and the old `/exchange-credentials` and `/risk-limits` URLs are no longer registered.

## Workflow follow-up

- Archive the change after implementation, review, and verification are complete.
- Confirm the archived main specs reflect the settings hub, navigation, and kill-switch placement.
