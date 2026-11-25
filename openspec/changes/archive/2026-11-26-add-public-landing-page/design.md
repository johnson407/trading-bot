# Design

## Context

See `proposal.md` for motivation and `specs/landing-page/spec.md` for the
required behaviour.

Current state:

- `routes/web.php` renders `Inertia::render('Welcome', ...)` for `GET /` with
  `canLogin`, `canRegister`, `laravelVersion`, and `phpVersion`.
- `resources/js/Pages/Welcome.vue` is the stock Laravel welcome page (Laravel
  logo, docs cards, external Laravel-hosted images).
- No test references `Welcome`, so the component can be replaced without
  breaking an existing assertion.
- The app already has a dark slate/blue theme (`resources/css/app.css`,
  Tailwind 3), Figtree, Breeze auth with `MustVerifyEmail`, and an
  authenticated dashboard.
- `ApplicationLogo.vue` is the stock Laravel mark and is used by the auth and
  authenticated layouts.

## Goals / Non-Goals

**Goals:**

- Replace the stock page with a self-contained full-width landing page that
  works for both newcomers and experienced traders in one narrative.
- Reuse the existing visual language and stack; no new dependencies.
- Keep the page maintainable as a single SFC, with semantic sections.
- Make the guest/signed-in routing behaviour and page contract executable in a
  Pest feature test.

**Non-Goals:**

- Rebranding the authenticated shell or auth pages.
- A public demo login or sandbox account.
- Marketing analytics, lead capture, pricing, or a CMS.
- Server-side rendering, new images, icon packages, or animation libraries.
- Changing email verification or any auth middleware.

## Decisions

### Rewrite `Welcome.vue` in place

Replace the existing SFC and keep the route/component name. This is the
smallest change that satisfies the request and avoids a new marketing layout or
route. Alternative: a new `Landing.vue` plus landing components, or an
audience-split page with tabs — both add structure and copy without changing the
outcome, so they are deferred until the page needs them.

If the file becomes unwieldy, extract small presentational sections into
`resources/js/Components/` at implementation time; no shared marketing component
library is planned.

### Route closure changes

- Keep `GET /` → `Welcome`.
- Pass only `canLogin` and `canRegister`; remove `laravelVersion` and
  `phpVersion` so no framework version data reaches the page.
- If `$request->user()` is present, redirect to `route('dashboard')`. Verified
  users reach the dashboard; unverified users follow Breeze's existing
  verification-notice flow, which is the intended signup path.

Alternatives considered: leaving the page visible to signed-in users (redundant
for owners) or redirecting only verified users (two different front-page
behaviours for one audience). One redirect is simpler and consistent.

### Landing wordmark instead of `ApplicationLogo`

The page uses a small inline grid mark plus the text “Grid Trading Bot”. Using
the stock Laravel logo on a public product page would contradict the product
brand; changing `ApplicationLogo` globally would expand scope into auth and
dashboard chrome. The app shell therefore keeps its current logo for now.

### Page composition

Top to bottom:

1. Header: wordmark, “Log in”, “Create free account”.
2. Hero: one-sentence value proposition, short supporting copy, the signup CTA,
   and a stylised dashboard card built from inline SVG/CSS (price line, grid
   ladder, sparkline). No real or implied performance figures.
3. Proof strip: four strategies, paper-first, gated live execution.
4. Strategies: four cards matching `docs/strategies.md` (Simple, Trailing,
   Martingale, Dynamic).
5. Paper-first safety: paper default, explicit live gate and confirmation, kill
   switch, position/open-order caps, daily-loss pause, per-bot stop-loss /
   take-profit / range exit.
6. Backtesting: saved runs, metrics, equity curves, side-by-side comparison.
7. How it works: create account → build and preview a ladder → run paper, enable
   live only when ready.
8. Closing CTA band and footer (wordmark, sign-in/register, paper-first line,
   “Not financial advice”).

Copy is plain and specific, uses British spelling to match the repo, and avoids
returns, income, or “risk-free” promises. Illustrative values in the hero card
are decorative only and are not labelled as results.

### Visual and motion implementation

Use Tailwind utilities and the existing CSS variables (`--surface-canvas`,
`--accent`, status colours), layered slate surfaces, and blue accents. All
graphics are inline SVG or CSS; no remote assets and no `chart.js` on this page.
Any decorative animation is CSS-only and wrapped in a
`prefers-reduced-motion: no-preference` query or disabled under
`prefers-reduced-motion: reduce`.

### Tests

Add `tests/Feature/LandingPageTest.php` using the existing Pest + Inertia
assertion style:

- guest `GET /` returns 200 and the `Welcome` component with
  `canLogin`/`canRegister`;
- the response does not expose `laravelVersion` or `phpVersion`;
- authenticated `GET /` redirects to `dashboard`.

Use `User::factory()` and `RefreshDatabase` following the existing feature
tests. The test covers the route contract, not visual layout.

## Risks / Trade-offs

- **Marketing claims drift from the product** → Restrict claims to documented
  behaviour and keep the strategy/safety copy aligned with `docs/strategies.md`
  and `docs/risk.md`.
- **A single large SFC becomes hard to edit** → Keep sections semantic; extract
  components only when the file grows beyond comfortable editing.
- **Decorative mock numbers read as real performance** → Keep the hero visual
  abstract or label it illustrative; never show currency returns, win rates, or
  user counts.
- **Signed-in owners cannot view the landing page** → Accepted; they have the
  dashboard, and the page targets strangers.
- **No SSR means the marketing copy waits for JavaScript** → Same trade-off as
  every existing Inertia page; acceptable for this app.
- **Contrast or motion regressions** → Reuse theme tokens, add visible focus
  styles, and honour reduced motion; check the built page manually at desktop
  and narrow widths.

## Migration Plan

No data migration. Deploying the code changes `/` for all users; rollback is a
normal revert of the route closure and `Welcome.vue`.

## Open Questions

None that change the specs or task breakdown; exact copy, spacing, and SVG
shapes are left to implementation.
