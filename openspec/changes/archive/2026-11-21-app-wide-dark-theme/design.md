# Design

## Context

The inspected application uses Vue, Inertia and Tailwind, with Breeze-derived layouts and shared controls. The committed application includes the dashboard and trading screens from the PRD demo surface, and the existing interface uses light utility classes. The isolated worktree is based on commit `5f12578`.

## Goals / Non-Goals

**Goals:** Establish reusable visual decisions across existing and forthcoming screens, preserve readability and responsive behavior, and make safety and financial states unambiguous.

**Non-Goals:** Change trading logic, routes, data contracts, or invent dashboard data; add a light/dark toggle; redesign workflows beyond what is needed for consistent presentation.

## Decisions

### Visual direction

Use a modern financial workspace: deep charcoal canvas, lighter layered panels, subtle borders, bright primary text, muted secondary text, and blue primary actions and navigation. Reserve green and red for meaningful financial or safety states, with labels or signs as independent cues. A dense terminal style was considered but fits onboarding and account pages less well; stark monochrome offers fewer useful semantic cues.

### Shared styling

Define semantic color and interaction tokens centrally in the existing Tailwind/CSS system, then apply them through shared layouts and components. Update page-specific utility classes where necessary. This fits the existing stack and reduces drift; introducing a new component library adds dependency and migration cost without a demonstrated need. Choose exact values during implementation and verify contrast against the spec.

### Information and interaction

Use aligned numeric columns, clear table headings, readable labels, explicit status text, and visible hover, focus, disabled, error and success states. Keep destructive and live-trading controls visually distinct. Retain existing responsive navigation and provide accessible overflow for wide tables rather than hiding financial information.

### Integration

Theme the committed dashboard and trading screens, preserving their existing data and controls. Apply the same visual system to guest and public screens as to the authenticated application.

## Risks / Trade-offs

- [Dark colors can reduce contrast or obscure focus] → Measure text and control contrast and check keyboard focus on representative screens.
- [Red and green can be ambiguous] → Retain signs, labels, and messages independently of color.
- [Parallel edits can conflict] → Work in the isolated worktree and coordinate integration of overlapping files.
- [Wide trading tables can be difficult on mobile] → Preserve accessible horizontal scrolling and verify controls at narrow widths.

## Migration Plan

Introduce shared theme tokens and components on the committed application base, then update screen groups and verify representative states. Deploy with the normal application release; rollback by reverting the theme change. No data migration is required.
