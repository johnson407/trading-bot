# Spec Delta

## MODIFIED Requirements

### Requirement: Alert content and ownership

Each alert SHALL store the owner, the optional bot, the incident type, a severity, a human-readable title and message, the observed context, the originating risk-event or activity-log record when one exists, and its creation time. The alert SHALL belong to the bot owner and SHALL remain readable after the bot is archived or permanently deleted. An archived bot SHALL remain associated with its alerts so the alert can show the bot's name. Permanent deletion SHALL clear the bot association.

#### Scenario: Alert records the incident

- **WHEN** an alert is created for a bot incident
- **THEN** it stores the owner, bot, incident type, severity, title, message, observed context, source record when available, and creation time

#### Scenario: Alert survives bot archiving

- **WHEN** a bot is archived after creating an alert
- **THEN** the alert remains readable with its original message and still shows the archived bot's name

#### Scenario: Alert survives bot deletion

- **WHEN** a bot is permanently deleted after creating an alert
- **THEN** the alert remains readable with its original message and a missing bot association
