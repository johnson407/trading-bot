# landing-page Specification

## Purpose

Present the public product story and signup path so a visitor can understand
Grid Trading Bot, trust its paper-first safety model, and create an account.

## Requirements

### Requirement: Public product landing page

The unauthenticated `/` route SHALL present a product landing page for Grid
Trading Bot that explains the product and its signup path. It SHALL NOT present
framework documentation links, framework version details, or the framework logo
as the product brand.

#### Scenario: Guest opens the front page

- **WHEN** an unauthenticated visitor requests `/`
- **THEN** the page shows the product wordmark, a value proposition, the four
  built-in strategies, the paper-first safety model, backtesting, and a call to
  action to create an account

#### Scenario: No framework marketing remains

- **WHEN** the landing page is rendered
- **THEN** it contains no Laravel or PHP version text, no framework documentation
  links, and no framework logo

### Requirement: Signup and sign-in calls to action

The landing page SHALL provide a primary action linking to registration when
registration is enabled, and a secondary action linking to sign in. Copy SHALL
state that email verification is required before the dashboard opens.

#### Scenario: Visitor creates an account

- **WHEN** a visitor activates the primary call to action
- **THEN** the registration screen opens

#### Scenario: Visitor signs in

- **WHEN** a visitor activates the secondary call to action
- **THEN** the sign-in screen opens

#### Scenario: Registration is unavailable

- **WHEN** registration is disabled
- **THEN** the landing page shows the sign-in action without a registration
  action

### Requirement: Signed-in visitors skip the landing page

A request to `/` from an authenticated user SHALL redirect to the dashboard.

#### Scenario: Signed-in visitor requests the front page

- **WHEN** an authenticated user requests `/`
- **THEN** the response redirects to the dashboard

### Requirement: Claims match implemented behaviour

Landing content SHALL describe only implemented product behaviour. It SHALL NOT
present fabricated performance figures, testimonials, user counts, or promises
of profit.

#### Scenario: Review product claims

- **WHEN** a visitor reads the strategy, safety, and backtesting sections
- **THEN** each claim matches the documented behaviour of the shipped system and
  no invented performance result is shown

#### Scenario: Live trading is described

- **WHEN** the landing page mentions live trading
- **THEN** it presents live execution as gated and deliberately enabled, not as
  a one-click or risk-free step

### Requirement: Accessible and responsive landing experience

The landing page SHALL use semantic sections and headings, keep every link
keyboard reachable with a visible focus indicator, remain usable at narrow
viewport widths, and stop decorative motion when reduced motion is requested.

#### Scenario: Visitor prefers reduced motion

- **WHEN** the visitor's environment requests reduced motion
- **THEN** decorative animation on the landing page is disabled

#### Scenario: Visitor uses a narrow screen

- **WHEN** the landing page is viewed at a narrow viewport
- **THEN** its copy, sections, and calls to action remain readable without
  clipping
