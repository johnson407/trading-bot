# Tasks: add-alert-grouping

## 1. Grouping service

- [x] 1.1 Create `app/Domain/Alerts/AlertGrouping.php` with owner-scoped `groups()` (30-day default, `type + bot_id`, total/unread counts, latest message/time, highest severity rank, 20/page, newest first), `recentOccurrences()` (five per group, one bounded query, no N+1), `markRead()` (only unread up to `through`), and a stable group-key helper.
- [x] 1.2 Add `tests/Unit/AlertGroupingTest.php` covering the 30-day window, counts, severity ranking, ordering, bot filter, archived/null bots, five-occurrence cap, bounded mark-read, and ownership.

## 2. Controller and routes

- [x] 2.1 Add `POST /alerts/groups/read` as `alerts.groups.read`.
- [x] 2.2 Update `AlertController@index` with `mode` (`grouped` default, `all`), grouped paginator + recent occurrences + `through`, existing all-mode paginator, bot filter in both modes.
- [x] 2.3 Add `AlertController@markGroupRead` validating `type`, nullable `bot_id`, and `through`; scope to the owner and redirect back.
- [x] 2.4 Add feature coverage for grouped props, mode switching, bot filters, group read bounds, ownership, and empty groups.

## 3. Alerts UI

- [x] 3.1 Update `resources/js/Pages/Alerts/Index.vue` with Grouped/All alerts tabs, grouped rows (severity, type, bot link, latest message/time, total/unread counts), expandable five-occurrence detail, View all with the same bot filter, mark-group-read, existing all-mode table, empty states, and pagination.
- [x] 3.2 Verify the frontend build and add file/interaction assertions where the existing test style supports them.

## 4. Documentation

- [x] 4.1 Update `docs/dashboard.md` alerts section and test map for grouped mode, All alerts, expand/View all, bounded mark-read, bot filter, and the new route/test names; verify `DashboardDocsTest`.

## 5. Integration verification

- [x] 5.1 Run the full PHP suite with the documented Docker environment overrides.
- [x] 5.2 Run PHPStan level 5 and Pint on changed PHP files.
- [x] 5.3 Run the frontend build and JS tests.
- [x] 5.4 Run `openspec validate add-alert-grouping --strict`, `git diff --check`, and a live-safety grep.

## 6. Workflow follow-up

- [x] 6.1 Request code review after implementation.
- [x] 6.2 Sync the `alerts` spec, archive the change, commit/push, and open a PR linked to issue #17 item 5.
