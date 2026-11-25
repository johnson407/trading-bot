# Proposal

## Why

The public `/` route still renders Laravel's stock Inertia welcome page, so a
stranger sees framework branding and docs links instead of what this product
does or why it is safe to try. Registration is open and the dashboard is built,
but nothing on the front page turns a visitor into a signup.

## What Changes

- Replace `resources/js/Pages/Welcome.vue` with a product landing page: hero and
  primary call to action, a short proof strip, the four built-in strategies,
  paper-first safety and risk controls, backtesting, a three-step "how it
  works", a closing call to action, and a footer.
- Primary action **Create free account** links to `register`; secondary action
  **Log in** links to `login`. Copy states that email verification is required
  before the dashboard opens.
- Signed-in visitors who request `/` are redirected to `dashboard`; the route
  stops passing Laravel and PHP version props.
- The landing page uses an inline grid wordmark rather than the stock Laravel
  `ApplicationLogo`. The rest of the app is unchanged.
- Copy describes only implemented behaviour: paper-first default, gated live
  execution, kill switch, position/open-order caps, daily-loss pause, and
  backtest comparisons. No performance figures, testimonials, or return
  promises.
- Styling stays within the existing dark slate/blue theme, Figtree, Tailwind 3,
  and Vue 3 + Inertia 2. No new dependencies and no external images.
- Add feature coverage for the guest render and the signed-in redirect.

## Capabilities

### New Capabilities

- `landing-page`: public front-page content, calls to action, product claims,
  and access behaviour for visitors and signed-in users.

### Modified Capabilities

None. The existing `app-visual-theme` requirements already govern public pages
and continue to apply unchanged.

## Impact

- `routes/web.php` — `/` closure props and signed-in redirect.
- `resources/js/Pages/Welcome.vue` — replaced.
- `tests/Feature/LandingPageTest.php` — new.
- No database, queue, engine, trading, exchange, dependency, or configuration
  changes.
