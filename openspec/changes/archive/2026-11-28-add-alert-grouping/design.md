# Alert Grouping Design

## Context

The alerts inbox currently lists every persisted alert newest first, 50 per page. `AlertRecorder` already suppresses exact repeats for the same `user_id + bot_id + type` inside the configured dedupe window, but a long-lived condition creates a new alert every window. The inbox therefore shows the same condition repeatedly, which is the fatigue this change addresses.

The alert row already carries `type`, `severity`, nullable `bot_id`, `message`, `created_at`, and `read_at`. No schema change is needed. Bot links, bot filtering, dedupe, retention, critical email, and the existing ungrouped view must keep working.

## Goals / Non-Goals

### Goals

- Make the default inbox show one row per `type + bot_id` group over the last 30 days.
- Show enough context in the group row to decide whether to open it: highest severity, type, bot, latest message/time, total count, and unread count.
- Let the owner expand a group to the five most recent occurrences, or open the full ungrouped list filtered to that bot.
- Let the owner mark a group read without marking occurrences newer than the row they saw.
- Keep the existing ungrouped inbox as an explicit “All alerts” mode.
- Preserve owner scoping and archived-bot readability.

### Non-Goals

- Browser push, web push, or new notification channels.
- Changing dedupe keys, dedupe windows, retention, or email delivery.
- Alert lifecycle states beyond read/unread.
- Grouping across users or across different bots.
- Reworking the alert creation pipeline or storing aggregate counters.
- Adding a database migration.

## Decisions

### Query-time grouping, no schema change

`AlertGrouping` will group alerts when the inbox is read. A stored group table would require ingestion changes, counter maintenance, and a migration for a view-only concern. Query-time grouping keeps every alert available for audit and lets the 30-day window change without data migration.

### Group key is `type + bot_id`

The same incident type on two bots is two groups; the same type on one bot is one group. A null `bot_id` is its own group because account-level alerts are not bot-specific. Grouping includes archived bots so history remains readable; the UI only links a bot whose `deleted_at` is null.

### Default window is 30 days

The grouped view shows alerts created in the last 30 days. This matches the approved scope, stays inside the 90-day retention window, and bounds the grouping query. The All alerts mode keeps the existing full-history, 50-per-page list.

### Group summaries and occurrences are fetched in bounded queries

The service will build group summaries with aggregate SQL:

- `COUNT(*)` for total count.
- `COUNT(*) FILTER (WHERE read_at IS NULL)` for unread count.
- `MAX(created_at)` for the latest time.
- Highest severity via an explicit rank (`critical` > `warning` > `info`), returning the label, not only the rank.

The service paginates group summaries at 20 groups per page, newest group first. For the page’s groups, it fetches the five most recent occurrences in one bounded query using a window function partitioned by `type + bot_id`; it does not issue one query per group. Rows are ordered by `created_at` descending, then `id` descending.

The latest message and title come from the latest occurrence in each group. The group row uses the highest severity in the group, even when the latest occurrence is less severe, so a critical event is not hidden by a later warning.

### Marking a group read is timestamp-bounded

`POST /alerts/groups/read` accepts `type`, nullable `bot_id`, and `through` (the group row’s latest `created_at`). It updates only the current user’s unread alerts with that type, bot, and `created_at <= through`. This prevents a delayed request from marking an occurrence that arrived after the user saw the group. The existing single-alert and mark-all-read actions remain.

### Controller and UI shape

`AlertController@index` gains a `mode` filter: `grouped` (default) or `all`. Grouped mode returns a paginator of group summaries with their recent occurrences and a `through` timestamp for the read form. All mode returns the existing 50-row alert paginator.

`Alerts/Index.vue` renders mode tabs, the grouped list, and the existing table. A group expands in place to show up to five occurrences. “View all” links to all mode with the same `bot_id` filter. The existing bot filter and ownership behavior apply in both modes.

### Severity ranking

Severity is a string today, so the service owns a small rank map. Unknown severities rank below `info` so a malformed value cannot hide a known critical alert; the UI falls back to the raw string.

## Risks / Trade-offs

- **Group query cost grows with 30 days of alerts** → Use aggregate SQL and indexes already present on `user_id`/`created_at`; paginate groups at 20 and occurrences at five per group. Verify the query plan in the feature test environment if the inbox is large.
- **A group can hide a newer critical occurrence inside an older group** → The group severity is the highest in the group, and the recent occurrences are ordered newest first. The group row also shows the unread count.
- **Marking a group read could hide a newer alert** → Bound the update by the group’s latest `created_at` and re-check ownership.
- **Archived bots can be grouped but not linked** → Include `deleted_at` in the bot payload and render plain text when archived.
- **Null `bot_id` groups are easy to mishandle** → Use `whereNull('bot_id')` for the group read and summary queries, and test the account-level case.
- **Mode/filter combinations can drift** → Keep one filter object for both modes and test grouped/all with and without `bot_id`.
- **Grouping changes the meaning of “newest first”** → Order groups by latest occurrence, not by the oldest alert in the group, and state this in the UI copy.

## Migration Plan

No schema or data migration. Deploy the application normally; the new route and service are additive. Rollback only removes the grouped UI and route; persisted alerts are unchanged.

## Open Questions

None. The 30-day window, group key, page sizes, expand count, and read semantics are decided.
