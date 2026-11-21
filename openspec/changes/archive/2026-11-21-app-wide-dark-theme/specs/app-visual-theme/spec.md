# Spec Delta

## Purpose

Provide a consistent dark visual experience that keeps trading information, controls, and feedback legible across the application.

## ADDED Requirements

### Requirement: Consistent dark presentation
The application SHALL present a cohesive dark theme across public, authentication, and authenticated screens, using layered dark surfaces and blue accents for ordinary navigation and primary actions.

#### Scenario: Navigate between areas
- **WHEN** a user moves between the welcome page, sign-in, trading screens, and account settings
- **THEN** surfaces, typography, navigation, and common controls follow the same dark visual language

### Requirement: Readable content and controls
Text, controls, borders, and focus indicators SHALL remain distinguishable against their backgrounds. Normal text SHALL meet WCAG 2.2 AA contrast of at least 4.5:1, large text at least 3:1, and relevant control and focus indicators at least 3:1 against adjacent colors.

#### Scenario: Navigate a form by keyboard
- **WHEN** a user tabs through a form
- **THEN** the focused control has a visible focus indicator and its label, value, and errors remain readable

### Requirement: Trading states are not color-only
The application SHALL communicate profit and loss, bot states, live mode, warnings, and errors using text or another non-color cue in addition to color.

#### Scenario: Inspect trading status
- **WHEN** a user views a bot's mode, state, or financial result
- **THEN** its meaning can be identified without relying on color alone

#### Scenario: Inspect a warning or error
- **WHEN** a warning or failure is displayed
- **THEN** its message identifies the condition independently of its color

### Requirement: Safety controls remain identifiable
The kill switch and live-mode warnings SHALL be visually distinct and clearly labeled wherever they are presented.

#### Scenario: Locate the kill switch
- **WHEN** a user navigates the authenticated application
- **THEN** the kill switch is identifiable by its label and distinct treatment

### Requirement: Responsive themed interface
The themed interface SHALL remain usable at desktop and mobile viewport widths, including navigation, forms, dialogs, and data tables.

#### Scenario: Use a narrow screen
- **WHEN** a user views a data-heavy page on a narrow viewport
- **THEN** its content and controls remain accessible without clipping, including through horizontal scrolling when needed
