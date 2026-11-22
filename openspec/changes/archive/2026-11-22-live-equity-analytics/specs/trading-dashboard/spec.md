# Spec Delta

## ADDED Requirements

### Requirement: Equity history

The dashboard SHALL show an account equity-history section with a chart, the selected range, and period return, peak equity, and maximum drawdown. It SHALL offer 7-, 30-, and 90-day ranges, defaulting to 30 days, and SHALL refresh without a full page reload at most once per minute.

#### Scenario: Open the default range

- **WHEN** a signed-in user opens the dashboard
- **THEN** the equity chart and metrics cover the last 30 days

#### Scenario: Change the range

- **WHEN** a user selects the 7-day or 90-day range
- **THEN** the chart and metrics update to that period

#### Scenario: Show an empty state

- **WHEN** no snapshots exist for the selected range
- **THEN** the section explains that equity history is still being collected

#### Scenario: Show a single point

- **WHEN** the selected range contains one snapshot
- **THEN** the point is shown without performance metrics

#### Scenario: Refresh without a reload

- **WHEN** a new snapshot is captured
- **THEN** the section reflects it without a full page reload within one minute

#### Scenario: Keep history private

- **WHEN** a user opens the dashboard
- **THEN** the equity history contains only that user's snapshots
