## ADDED Requirements

### Requirement: Parameter sweep pages
Authenticated owners SHALL be able to create, list, and inspect parameter sweeps. The create page SHALL select one grid bot, timeframe, date range, and multiple center prices, spacing values, and level counts, and SHALL show the resulting combination count. The detail page SHALL list each run's swept values and outcome, poll while runs are active, and link to individual runs and the comparison view. Sweeps and runs belonging to another owner SHALL NOT be visible.

#### Scenario: Creates a sweep from the dashboard
- **WHEN** an owner opens the sweep create page, selects an owned grid bot, and submits valid multi-value parameters
- **THEN** the page redirects to the new sweep detail page

#### Scenario: Shows combination count before submission
- **WHEN** an owner changes the number of center prices, spacing values, or level counts
- **THEN** the create page displays the resulting run count and warns when it exceeds the configured maximum

#### Scenario: Keeps another owner out
- **WHEN** a user requests a sweep or sweep run owned by someone else
- **THEN** the response is not found
