# Spec Delta

## ADDED Requirements

### Requirement: Grid activity view

The bot detail page SHALL link to a grid activity page for an owned bot. The page SHALL show a price-history line, each stored grid's levels clipped to its displayed lifetime, fills and cancels in the selected window, and controls to toggle each marker type. It SHALL offer 1-hour, 6-hour, 24-hour, and 7-day ranges, defaulting to 24 hours. Windows are half-open: events at or after the start and before the end belong to the window.

#### Scenario: Open grid activity from bot detail

- **WHEN** a user opens the detail page of an owned bot
- **THEN** a link to that bot's grid activity page is available

#### Scenario: Default window

- **WHEN** a user opens the grid activity page without selecting a range
- **THEN** the chart and summary cover the last 24 hours

#### Scenario: Change the window

- **WHEN** a user selects the 1-hour, 6-hour, or 7-day range
- **THEN** the price line, grid lifetimes, markers, and summary update to that window

#### Scenario: Grid lifetimes are visible

- **WHEN** several stored grids existed during the selected window
- **THEN** each grid's levels are drawn only for its displayed lifetime, and the current grid is visually distinguished

#### Scenario: Current ladder is not presented as trading after a stop

- **WHEN** a bot is paused or stopped but still has a current grid
- **THEN** that grid's levels end at the bot's last successful tick and are labeled as the current ladder rather than as active trading

#### Scenario: Fills and cancels are marked

- **WHEN** the selected window contains fills and canceled orders
- **THEN** each fill and cancel is plotted at its price and time, with controls to show or hide each marker type

#### Scenario: Accessible data fallback

- **WHEN** a user cannot use the chart
- **THEN** the page provides a table of the plotted price points, fills, and cancels

#### Scenario: Bounded output

- **WHEN** the window contains more markers or grids than the configured caps
- **THEN** the page shows the most recent entries up to each cap and states how many of the total are displayed

#### Scenario: Visible page refresh

- **WHEN** the grid activity page is visible and new fills or cancels occur
- **THEN** the chart and summary update without a full page reload within 30 seconds, and polling pauses while the tab is hidden

#### Scenario: Another user cannot open grid activity

- **WHEN** a user requests the grid activity page for a bot they do not own
- **THEN** the request is denied without revealing the bot's data

### Requirement: Grid activity churn summary

The page SHALL show a churn summary for the selected window. It SHALL include the fill count, all-cause cancel count, cancel-to-fill ratio (or "no fills" when there are none), stored grid revision count, median lifetime of grids replaced in the window, current spacing, and dynamic-grid spacing hysteresis. It SHALL label cancels as including pause, stop, operator, and grid-replacement causes, and state that restart re-arms are not stored revisions.

#### Scenario: Churn summary definitions

- **WHEN** the selected window contains grid activity
- **THEN** the summary distinguishes stored grid revisions from restart re-arms, excludes the current grid from the median lifetime, and labels the cancel count as including pause, stop, operator, and grid-replacement cancels

#### Scenario: No fills in the window

- **WHEN** the window contains cancels but no fills
- **THEN** the cancel-to-fill ratio is shown as "no fills"

#### Scenario: Spacing matches the strategy

- **WHEN** the bot uses a dynamic grid
- **THEN** the current spacing comes from the latest stored ladder and the configured spacing hysteresis is shown
- **AND WHEN** the bot uses a static grid
- **THEN** the current spacing comes from its strategy configuration and no hysteresis value is shown

### Requirement: Grid activity price history

The page SHALL obtain historical prices for the bot's exchange and symbol from public exchange data without private credentials. When the window cannot be served, it SHALL still render grids, fills, cancels, and counts, and SHALL state that price history is unavailable. When the window contains no grids, fills, or cancels, it SHALL show an empty state instead of a chart, whether or not price history is available.

#### Scenario: Public price history

- **WHEN** a user opens grid activity for a supported symbol and exchange
- **THEN** the price line is built from public historical candles and no API key or secret is required

#### Scenario: Price history unavailable

- **WHEN** historical prices cannot be retrieved for the selected window
- **THEN** the page shows the grids, fills, cancels, and summary without a price line and states that price history is unavailable

#### Scenario: Price history available but no activity

- **WHEN** the selected window contains candles but no grids, fills, or cancels
- **THEN** the page shows an empty state instead of a price-only chart
