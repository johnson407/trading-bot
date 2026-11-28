# Design

## Context

See `proposal.md` for motivation. The current `Dashboard.vue` renders, in order: a layout header band, engine/error banners, a full-width attention panel, seven equal stat cards, the equity card, the activity table, and footer links. Every section uses `p-6`, so related figures are far apart while the portfolio total is not the focal point.

Two implementation facts shape this design:

- `App\Domain\Analytics\EquityHistory::forUser()` returns points and metrics scoped to the requested range. There is no range-independent latest snapshot, and `summary` has no equity field.
- `EquityChart.vue` hardcodes `h-80` on its chart container, so the dashboard cannot size the chart without changing the component.

`DashboardTest` asserts several literal strings in `Dashboard.vue`; `docs/dashboard.md` says the attention panel is hidden when no bot needs attention. Tailwind resolves to 3.4, whose line-clamp utilities are available, but the layout keeps attention reasons fully readable rather than clamping them.

## Goals / Non-Goals

**Goals:**

- Make account equity the dominant figure and keep bot operations visible beside it.
- Establish a compact, repeatable spacing and type rhythm without shrinking body text below 14px or meta text below 12px.
- Keep the latest equity headline stable when the 7/30/90-day range changes.
- Keep the attention rail bounded and usable with the keyboard.
- Preserve all existing dashboard behavior and test literals.

**Non-Goals:**

- No route, schema, configuration, or polling-cadence changes.
- No redesign of other pages or the authenticated shell.
- No new chart library, icon set, or CSS dependency (including the line-clamp plugin).
- No activity pagination or new activity detail page.

## Decisions

### 1. Two-band composition with a slim in-content header

Desktop uses a 12-column grid: equity hero `lg:col-span-8`, operations rail `lg:col-span-4`, activity full width below. Below `lg`, the sections stack in that order. The `AuthenticatedLayout` `#header` slot is dropped for this page; the title, Create bot, and danger-styled Kill switch move into a 40px in-content row inside `max-w-7xl`.

The Kill switch keeps the existing danger treatment from `Bots/Index.vue` and stays out of primary navigation. Footer links are removed because the header now carries the two actions and Settings owns credentials.

*Alternative rejected:* keeping the header band and seven cards. It preserves the flat hierarchy the redesign is meant to fix.

### 2. Latest equity snapshot lives on `equityHistory`

`EquityHistory::forUser()` gains one additive key:

```php
'latest' => ['at' => string, 'equity' => string] | null
```

It is read from the user's newest snapshot regardless of the requested range, using a single `orderByDesc('captured_at')->first(['captured_at', 'equity'])` query. The hero shows `equityHistory.latest.equity` and `equityHistory.latest.at`. Range changes continue to update only `points`, `metrics`, `range_days`, `has_history`, and `collecting`.

*Alternatives rejected:*

- Range-end point: the headline would jump or disappear when switching ranges, which is wrong for "Account equity".
- Add equity to `summary`: summary reloads every five seconds, adding a snapshot query to that cadence and mixing a periodic snapshot into the live summary payload.

### 3. Configurable chart height

`EquityChart.vue` gains `heightClass` (String, default `h-80`) applied to the chart container. The default keeps `Backtests/Show.vue` and `Backtests/Compare.vue` unchanged. Dashboard passes `h-56 sm:h-72 lg:h-80`, so mobile regains vertical space while desktop keeps the existing chart size.

*Alternative rejected:* overriding height from the parent with CSS. The chart container's fixed class and Chart.js's `maintainAspectRatio: false` need an explicit height on that element.

### 4. Attention rail is always present and bounded

The rail renders even when `botHealth` is empty, showing a text-and-dot `All bots healthy` state. The list is capped at `max-h-72` with `overflow-y-auto`, `tabindex="0"`, an `aria-label`, and a visible `focus-visible` ring. Each row keeps the bot name, mode, issue chips, reason, one combined last-tick/last-success line, and the existing resume controls. The reason wraps naturally; no line-clamp dependency is introduced.

A count badge in the header makes hidden entries discoverable. The fleet summary sits in a separate card below the rail, so the bounded list never pushes it off screen.

*Alternatives rejected:* hiding the panel when empty (collapses the rail and contradicts the agreed composition); unbounded rows (the rail would grow with fleet size); `line-clamp-2` (not available in Tailwind 3.2 without a new dependency).

### 5. Fleet counts replace seven stat cards

A five-column strip shows Bots, Running, Paused, Idle, and Stopped with hairline dividers. With no bots, it shows `No bots yet` and a Create link. Realized and Unrealized P&L move into the hero, where they support the account-equity headline. This removes the 4+3 grid hole and the repetition of seven bordered cards.

### 6. Compact activity table

The existing ten-row payload stays. The table uses `px-4 py-2` cells, a 12px uppercase header, and a 12px time column. The Type column is `hidden sm:table-cell`, so narrow screens keep Time, Bot, and Message readable; the wrapper keeps `overflow-x-auto` as a fallback. Archived bot names remain plain text.

### 7. Density tokens

| Token | Value |
| --- | --- |
| Base rhythm | 4px; use 4 / 8 / 12 / 16 / 20 / 24 only |
| Page padding | `px-4 sm:px-6 lg:px-8`, `pt-5 pb-10` |
| Band gap | 20px desktop, 16px mobile |
| Card padding | 16px mobile, 20px desktop |
| List row | 10px vertical padding, ~36-40px tall |
| Page title | 20px semibold (`text-xl`, matching Bots) |
| Hero value | 30px mobile, 36px desktop |
| Secondary value | 18px |
| Body | 14px |
| Meta / eyebrow / table head | 12px minimum |
| Numerals | `tabular-nums` everywhere |
| Surfaces | `rounded-lg border border-slate-700 bg-slate-800`; hairline dividers instead of nested cards |
| Accents | blue primary, rose danger; status color never the only cue |

### 8. Accessibility

- Meta text uses `text-slate-400` on `bg-slate-800`; `text-slate-500` is reserved for non-essential decoration.
- Range buttons, action links, and the attention scroll region get `focus-visible` rings.
- The range control is a labeled group; table headers use `scope="col"`.
- P&L keeps its sign and label; issue chips keep text; the healthy state says "All bots healthy".
- The attention list is a named, keyboard-scrollable region; the existing engine status live region is unchanged.
- No text below 12px; no layout depends on hover.

## Risks / Trade-offs

- **One extra snapshot query per equity load** -> A single indexed latest-row query at the existing 60-second cadence and on range changes; acceptable.
- **A bounded rail hides some issues** -> The count badge and keyboard-scrollable region expose the total; ordering stays paused-by-engine, stale, error.
- **Compact spacing can become cramped** -> 14px body / 12px meta floors, 16-20px card padding, and 16-20px band gaps.
- **Removing the header band weakens page identity** -> The in-content title row keeps the same heading and actions at the top of the content column.
- **Test literals and docs drift** -> Preserve `Equity history`, `Period return`, `Peak equity`, `Maximum drawdown`, `Collecting history`, `equityHistory.available_ranges`, `EquityChart`, `60_000`, `router.get(`, `route('dashboard')`, the `period_return === null` expression, and `—`; update the docs sentence about the hidden attention panel.
- **Tailwind 3.2 lacks line clamping** -> Reasons wrap; the rail's max height handles overflow.

## Migration Plan

- Additive PHP payload key only; no migration, backfill, or config change.
- Deploy PHP and frontend together. The old frontend ignores `latest`; the new frontend falls back to `—` if it is absent.
- Rollback is a normal revert; no persisted state changes.
- Verify with `npm run build`, `npm run test:js`, and the focused Pest suites (`DashboardTest`, `EquityHistoryTest`, `BotHealthTest`, `BotActionsTest`, `DashboardDocsTest`), then the full suite.

## Open Questions

None.
