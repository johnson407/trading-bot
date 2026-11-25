# Tasks

## 1. Routing contract

- [x] 1.1 Add `tests/Feature/LandingPageTest.php` covering guest `GET /` (200, `Welcome` component, `canLogin`/`canRegister` present, `laravelVersion`/`phpVersion` absent) and authenticated `GET /` redirecting to `dashboard`; run the focused test and confirm the new assertions fail against the current route.
- [x] 1.2 Update the `/` closure in `routes/web.php` to accept the request, redirect authenticated users to `dashboard`, pass only `canLogin`/`canRegister`, and run `LandingPageTest` to green.

## 2. Landing page

- [x] 2.1 Replace `resources/js/Pages/Welcome.vue` with the page shell: inline grid wordmark, header links to log in/register, hero value proposition, primary/secondary CTAs with the email-verification note, and the inline SVG/CSS dashboard visual; verify with `npm run build`.
- [x] 2.2 Add the proof strip, the four strategy cards (Simple, Trailing, Martingale, Dynamic), and the paper-first safety section matching `docs/strategies.md` and `docs/risk.md`; verify with `npm run build`.
- [x] 2.3 Add the backtesting section, three-step how-it-works, closing CTA band, and footer with the “Not financial advice” line; verify with `npm run build`.
- [x] 2.4 Make the page responsive, keyboard reachable, and reduced-motion safe; verify by checking `/` at a narrow viewport and with reduced motion enabled in the browser.

## 3. Integration checks

- [x] 3.1 Run `docker compose exec -u www-data -e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync app php artisan test --filter=LandingPageTest` and confirm it passes.
- [x] 3.2 Run `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `docker compose exec -u www-data app vendor/bin/pint --dirty`, and `docker compose exec -u www-data app npm run build`; confirm all pass.
- [x] 3.3 Manually verify `/` as a guest (all sections, both CTAs, no framework branding) and as a signed-in user (redirect to dashboard), then update the change status.

## Workflow follow-up

- Review the implementation against the `landing-page` delta spec before archiving.
- Archive the change with the OpenSpec archive workflow once implementation and review are complete.
