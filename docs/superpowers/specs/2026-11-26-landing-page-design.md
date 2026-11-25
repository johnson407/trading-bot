# Landing Page — Design

Status: proposed, awaiting human approval
Date: 2026-11-26

## 1. Intent

The public `/` route still renders Laravel's stock Inertia welcome page: Laravel
logo, Laravel docs links, and framework version props. It says nothing about this
product and gives a stranger no reason to sign up.

Replace it, in place, with a single long-scroll landing page that sells the
product to a stranger. The page must serve both a crypto-curious trader who has
never run a bot and an experienced trader who cares about strategies and
backtests. The promise they should leave with: **grid bots on real market data,
paper-first — you risk nothing until you deliberately unlock live trading.**

## 2. Goals

- Rewrite `resources/js/Pages/Welcome.vue` as the landing page; keep `GET /`
  rendering `Welcome` from `routes/web.php`.
- A stranger understands what the product does, why it is safe to try, and what
  to click, within the first screen.
- Primary CTA: **Create free account** → `register`. Secondary: **Log in** →
  `login`. Copy sets the expectation that email verification is required before
  the dashboard opens.
- Cover, in order: hero, proof strip, the four built-in strategies, paper-first
  safety, backtesting, three-step "how it works", closing CTA, footer.
- Responsive from ~360 px up; keyboard accessible; visible focus states; motion
  respects `prefers-reduced-motion`.
- Stay inside the existing stack and look: Vue 3 + Inertia 2 + Tailwind 3,
  Figtree, slate-950 canvas, blue-400 accent. No new dependencies, no external
  images.

## 3. Non-goals (v1)

- A public demo login or read-only sandbox. The only seeded account is
  `demo@example.com` locally; it must not be advertised.
- Rebranding the authenticated app, auth pages, or `ApplicationLogo`. This is a
  landing-page change only.
- Pricing, plans, blog, contact form, newsletter capture, testimonials, or a
  marketing analytics integration.
- Dark/light toggle; the app is dark-only.
- Sitemap, robots, OG image generation, or i18n.
- Extracting a shared marketing layout or component library.

## 4. Anti-goals

These would make the page a failure even if it renders:

- Fabricated P&L, win rates, user counts, or testimonials presented as results.
- "Guaranteed", "passive income", or risk-free-return claims.
- Copy implying live trading is a one-click step. The live gate, credential
  check, balance check, and exact confirmation are the product's promise.
- Exposing demo credentials, API keys, or internal engine endpoints.
- Decorative animation that ignores reduced-motion or hurts contrast.

## 5. Constraints

- Tailwind 3 config in `tailwind.config.js`; design tokens in
  `resources/css/app.css` (`--surface-canvas`, `--accent`, status colors).
- `docs/dashboard.md`, `docs/exchanges.md`, and `docs/risk.md` are executable
  test fixtures — do not edit them.
- Money values: none are shown on the landing page. Any illustrative number in
  the hero mock must be clearly decorative and not a real performance claim.
- The four strategies and risk features must match `docs/strategies.md` and
  `docs/risk.md`.
- No backend behavior changes except the `/` closure (props and auth redirect).

## 6. Approaches

- **A. Rewrite in place** — replace `Welcome.vue` with the new long-scroll page,
  keep the route and component name. Minimal structure, no new abstractions,
  reuses the dashboard look. Chosen.
- **B. Dedicated marketing landing** — new page plus landing components and a
  heavier visual treatment. Better showpiece, more files and craft; rejected for
  this round because it is more than the requested index-page replacement.
- **C. Audience-split landing** — tabs for newcomers vs. experienced traders.
  Rejected: doubles copy, splits the single signup action, and risks feeling
  gimmicky.

## 7. Decisions

- `routes/web.php`: keep `GET /` → `Inertia::render('Welcome')`; pass
  `canLogin` and `canRegister` only; drop `laravelVersion` and `phpVersion`.
- Signed-in users hitting `/` redirect to `dashboard` (they already have the
  app; the landing page is for strangers). Unverified users land on Breeze's
  verification notice, which is the intended signup flow.
- The page header uses an inline grid-mark wordmark ("Grid Trading Bot"), not
  `ApplicationLogo` — strangers must not see Laravel branding. The rest of the
  app keeps the existing logo for now.
- Hero visual is a stylised dashboard card built from inline SVG/CSS: a price
  line, a ladder of buy/sell levels, and a small P&L sparkline. All values are
  labelled as illustrative, or unlabelled decoration.
- Copy tone: plain, confident, specific. British spelling to match the repo
  (e.g. "stylised", "optimise").
- Footer includes: wordmark, the paper-first line, log in/register links, and a
  "Not financial advice" note.
- `Head`: title "Grid Trading Bot — Paper-first crypto grid bots" and a meta
  description; no external assets.
- Keep the page as one SFC. Extract a component only if the file becomes
  unwieldy during implementation.
- Add `tests/Feature/LandingPageTest.php`: guests get the `Welcome` component
  with `canLogin`/`canRegister`; authenticated users are redirected to
  `dashboard`.

## 8. Left to the builder

- Final wording of every headline, section, and CTA within the tone above.
- Spacing, type scale, gradient treatment, and exact SVG shapes.
- Whether the proof strip is part of the hero or its own band.
- Test names and fixture setup, following existing Pest patterns.

## 9. Verification

- `docker compose exec -u www-data ... php artisan test --filter=LandingPageTest`
- `docker compose exec -u www-data app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
- `docker compose exec -u www-data app vendor/bin/pint --dirty`
- `docker compose exec -u www-data app npm run build`
- Manual check at `/` for guest and signed-in users.
