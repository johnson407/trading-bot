# Spec Delta

## MODIFIED Requirements

### Requirement: Bot detail and grid view

The bot detail page SHALL show the bot's grid levels, open orders, recent fills, inventory, and realized and unrealized profit and loss. For a grid level with an open order whose side differs from the level's plan side, the page SHALL show both the plan side and the actual order side. The view SHALL update within five seconds of a change.

#### Scenario: Inspect a running bot

- **WHEN** a user opens the detail page of a running bot
- **THEN** the page shows its grid, open orders, recent fills, inventory, and profit and loss

#### Scenario: Opposite order at a plan level

- **WHEN** a grid level's plan side is buy but it holds an open sell order
- **THEN** the page shows the level's plan side and the order's actual sell side

#### Scenario: Reflect a fill

- **WHEN** a fill occurs
- **THEN** the detail page shows the fill and updated inventory within five seconds
