# alerts Specification Delta

## ADDED Requirements

### Requirement: Grouped alert inbox

The alerts inbox SHALL offer a grouped view that reduces repeated alert rows without changing alert storage, deduplication, delivery, or retention.

#### Scenario: Default grouped view

- **WHEN** an authenticated user opens the alerts inbox without selecting the all-alerts view
- **THEN** alerts from the last 30 days are grouped by alert type and bot, newest group first, with 20 groups per page

#### Scenario: Group summary

- **WHEN** the grouped view renders a group
- **THEN** the group shows its highest severity, alert type, bot link when the bot still exists, latest message, latest creation time, total alert count, and unread count

#### Scenario: Expand a group

- **WHEN** the user expands a group
- **THEN** the five most recent occurrences are shown and a View all action opens the ungrouped list with the same bot filter

#### Scenario: Mark a group read

- **WHEN** the user marks a group read
- **THEN** only unread alerts in that group at or before the group's displayed latest alert are marked read

#### Scenario: All alerts view

- **WHEN** the user selects All alerts
- **THEN** the existing ungrouped 50-per-page inbox is shown with the same bot filter and mark-read behavior

#### Scenario: Bot filter

- **WHEN** a bot filter is applied
- **THEN** both grouped and ungrouped views contain only alerts for that owned bot

#### Scenario: Ownership

- **WHEN** a user requests grouped alerts or marks a group read
- **THEN** only their own alerts are returned or changed

#### Scenario: Empty grouped view

- **WHEN** no alerts fall inside the 30-day window
- **THEN** the grouped view shows an empty state and the All alerts toggle remains available
