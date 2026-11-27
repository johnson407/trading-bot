# Spec Delta

## ADDED Requirements

### Requirement: Source-backed creation UI

When the bot creation form is opened from a completed backtest run, it SHALL use advanced paper-only source mode, show a source banner, render source-derived fields as read-only, keep the name editable, hide live and current-price controls, and submit the source run ID.

#### Scenario: Source mode opens in advanced paper-only layout
- **WHEN** the form is opened with a source backtest run
- **THEN** it starts in advanced mode, hides quick setup and live controls, and shows a paper-only badge

#### Scenario: Source banner explains provenance and idle creation
- **WHEN** the source-backed form renders
- **THEN** the banner shows the source run and states that risk limits are current and the bot is created idle

#### Scenario: Name is editable while source settings are read-only
- **WHEN** the user edits the source-backed form
- **THEN** the name can be changed and source-derived settings cannot

#### Scenario: Live and current-price controls are hidden
- **WHEN** the source-backed form renders
- **THEN** no live-mode selector, live confirmation input, or "use current price" action is shown

#### Scenario: No source keeps the existing form
- **WHEN** the form is opened without a source backtest run
- **THEN** the existing quick/advanced, paper/live, and current-price behavior is unchanged

#### Scenario: Source defaults are applied before watchers
- **WHEN** the source-backed form initializes
- **THEN** source values are applied before default-field watchers can overwrite them

### Requirement: Source-backed preview parity

The source-backed form's preview SHALL use the same server-derived settings as submission.

#### Scenario: Preview uses the source derivation
- **WHEN** the source-backed form requests a preview
- **THEN** the request includes the source run ID and the preview uses the same snapshot-derived settings as store

### Requirement: Backtest comparison on bot detail

When a bot has a source backtest run, its detail page SHALL show a comparison card with source and paper net P&L, percentage returns, fees, fills, deltas, source and current capital, and a modified-since-creation indicator.

#### Scenario: Comparison shows both result sets
- **WHEN** the owner views a bot with a source backtest run
- **THEN** the page shows the source run and the source and paper net P&L, return percentage, fees, and fill counts

#### Scenario: Return units are percentages
- **WHEN** both the paper and backtest returns are five percent
- **THEN** the return delta is zero percentage points, not a mixed-unit value

#### Scenario: Modified source settings are indicated
- **WHEN** the bot's capital, fee, strategy configuration, or protective exits differ from the source snapshot
- **THEN** the card shows a modified-since-creation indicator and displays both source and current capital

#### Scenario: Zero capital is handled safely
- **WHEN** the paper or source capital is zero
- **THEN** the affected return is null and the comparison does not divide by zero

#### Scenario: Missing source metrics are handled
- **WHEN** the source run has null or empty metrics
- **THEN** the card shows an unavailable value rather than a fabricated number

#### Scenario: No paper fills are handled
- **WHEN** the paper bot has no fills yet
- **THEN** the card shows zero fills and does not error

### Requirement: Comparison refresh and source absence

The comparison SHALL include a caveat about differing periods, execution, risk limits, and edits, refresh with the bot's five-second polling, and SHALL not render when the source link is absent.

#### Scenario: Caveat is shown
- **WHEN** the comparison card renders
- **THEN** it states that periods, close-only backtest fills, live paper data, current risk limits, and post-creation edits can differ

#### Scenario: No source means no comparison
- **WHEN** the bot has no source backtest run
- **THEN** the comparison section is not shown

#### Scenario: Deleted source removes the comparison
- **WHEN** the source backtest run has been deleted
- **THEN** the link is cleared and the comparison section is not shown, while the bot remains usable

#### Scenario: Comparison refreshes with the bot
- **WHEN** the bot detail page refreshes
- **THEN** the paper values in the comparison refresh with the existing five-second polling
