# Add Alert Grouping

## Why

Alert deduplication only suppresses repeats inside the configured time bucket. A bot that keeps breaching the same limit still fills the inbox with one row per bucket, which buries new or different incidents and makes the page hard to scan.

Issue #17 asks for fewer repeated alerts in the app. This change groups recurring alerts by type and bot, shows how often each incident repeated, and keeps the full history available. Out-of-app push and new notification channels are not part of this change; critical email delivery stays unchanged.

## What Changes

- Make **Grouped** the default Alerts view over the last 30 days.
- Group alerts by `type + bot_id`, newest group first, 20 groups per page.
- Each group row shows the highest severity in the group, the type label, the bot link, the latest message, the latest timestamp, the total alert count, and the unread count.
- Expanding a group shows its five most recent occurrences.
- **View all** in a group switches to the existing ungrouped list with the same bot filter.
- **Mark group read** marks only the unread alerts in that group up to the group's latest timestamp, so alerts recorded later stay unread.
- Add an **All alerts** toggle that preserves the current ungrouped 50-per-page table and its behavior.
- Keep the existing bot filter, bot links, severity display, mark-one/mark-all behavior, and owner scoping.
- Preserve alert creation, deduplication, delivery, retention, and non-secret content unchanged.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `alerts`: the alert inbox gains a default 30-day grouped view, group expansion, group-scoped mark-read, and an all-alerts toggle, while the existing ungrouped inbox remains available. Deduplication, delivery channels, retention, and content rules are unchanged.

## Impact

- `app/Http/Controllers/AlertController.php` — grouped queries and group mark-read.
- New owner-scoped `app/Domain/Alerts/AlertGrouping.php` service.
- `resources/js/Pages/Alerts/Index.vue` — grouped/default views, expansion, and toggle.
- Feature tests for grouping, counts, ordering, 30-day window, bot filter, group mark-read, ownership, and the ungrouped view.
- Alert documentation updates.
- No database migration, no new dependencies, and no change to critical email delivery.
