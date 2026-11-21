# Proposal

## Why

The current interface uses disparate light default styling that does not present a cohesive trading product. An app-wide dark theme will make trading information and controls easier to scan while giving every screen a consistent, professional appearance.

## What Changes

- Establish a modern financial workspace theme with layered charcoal surfaces, readable typography, subtle borders, and blue primary actions.
- Apply the theme to the welcome and authentication experience, authenticated navigation, dashboard, bots, credentials, account settings, and shared controls and feedback.
- Make financial results, operational states, live-mode warnings, and the kill switch distinguishable with text as well as color.
- Preserve responsive usability and existing behavior across the committed dashboard and trading screens.

## Capabilities

### New Capabilities

- `app-visual-theme`: Consistent dark presentation, interaction states, and legible status communication across the application.

### Modified Capabilities

None. No main specs are currently present.

## Impact

Vue pages and shared components, layouts, Tailwind configuration and application styles are affected. Existing routes, trading behavior, and data contracts are unchanged. The worktree is based on commit `5f12578`, which includes the application and PRD demo surface; theme work should account for those existing screens.
