# Spec Delta

## ADDED Requirements

### Requirement: Dashboard composition

The dashboard SHALL use a portfolio-first composition: on desktop, an equity hero beside an operations rail with recent activity full width below; on narrow screens, the same sections in one readable column. Recent activity SHALL stay compact and avoid horizontal clipping by omitting non-essential columns rather than truncating messages. Account equity SHALL remain the most prominent figure while triage and fleet counts stay visible.

#### Scenario: Desktop composition

- **WHEN** a signed-in user opens the dashboard on a desktop viewport
- **THEN** the equity hero and operations rail appear side by side, and recent activity spans the content width below them

#### Scenario: Narrow-screen composition

- **WHEN** a signed-in user opens the dashboard on a narrow viewport
- **THEN** the equity hero, operations rail, and recent activity stack in one column without horizontal clipping

#### Scenario: Compact activity on a narrow screen

- **WHEN** the dashboard is viewed on a narrow viewport
- **THEN** non-essential activity columns are omitted and activity messages remain readable

#### Scenario: Equity is the primary figure

- **WHEN** the dashboard renders with both account equity and bots needing attention
- **THEN** the account equity figure is visually more prominent than fleet counts and triage rows, and the triage remains visible

### Requirement: Latest equity headline

The dashboard SHALL show the latest account equity snapshot as its headline figure, independent of the selected equity-history range, together with the snapshot time. Changing the selected range SHALL update the chart and range metrics without changing the headline. When no snapshot exists, the headline SHALL show a placeholder rather than zero or a stale value.

#### Scenario: Headline is independent of range

- **WHEN** a user switches the equity-history range between 7, 30, and 90 days
- **THEN** the chart and range metrics update while the account-equity headline and snapshot time stay on the latest snapshot

#### Scenario: No snapshot yet

- **WHEN** the user has no equity snapshots
- **THEN** the headline shows a placeholder and does not display zero as account equity

#### Scenario: Latest snapshot is private

- **WHEN** a user opens the dashboard
- **THEN** the headline reflects only that user's latest equity snapshot

### Requirement: Attention rail capacity

The dashboard's needs-attention section SHALL keep a stable place in the composition when no bot needs attention and SHALL show an explicit healthy state. When entries exceed the available height, the list SHALL scroll within a bounded region while the fleet summary and the rest of the page remain in place.

#### Scenario: No bots need attention

- **WHEN** no non-archived bot is stale, paused by the engine, or in error
- **THEN** the attention section shows an "All bots healthy" state instead of disappearing

#### Scenario: Many bots need attention

- **WHEN** more attention entries exist than fit in the bounded rail
- **THEN** the list scrolls inside the rail and the fleet summary stays visible below it

#### Scenario: Keyboard access to the rail

- **WHEN** a keyboard user focuses the attention list
- **THEN** the bounded list can be scrolled with the keyboard and shows a visible focus indicator
