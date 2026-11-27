# Spec Delta

## ADDED Requirements

### Requirement: Dashboard bot health

The dashboard SHALL show a triage section listing only the user's non-archived bots that need attention: running bots that are stale, bots paused by the engine with desired status running, and bots in an error state or running with a non-blank recorded error. Each entry SHALL show the bot name, state, last tick or last successful tick, and the reason, and SHALL refresh within five seconds.

#### Scenario: Engine-paused bot appears

- **WHEN** the engine pauses a running bot and leaves its desired status running
- **THEN** the triage section shows the bot with a paused-by-engine badge and the engine's last error as the reason

#### Scenario: Error bot appears

- **WHEN** a bot is in an error state or is running with a non-blank recorded error
- **THEN** the triage section shows the bot with an error badge and the recorded error as the reason

#### Scenario: Healthy bots are omitted

- **WHEN** a bot is running normally, idle, stopped, or paused by the user
- **THEN** it does not appear in the triage section

#### Scenario: Archived bots are omitted

- **WHEN** a bot is archived
- **THEN** it does not appear in the triage section

#### Scenario: Health changes are reflected

- **WHEN** a bot's health changes
- **THEN** the triage section reflects the change within five seconds

### Requirement: Stale bot detection

The dashboard SHALL mark a bot stale only when the bot is running with desired status running, the engine heartbeat is available, the engine start time is known, the engine has been up for at least the effective stale threshold, and the bot's last successful tick (or last tick or creation time) is older than that threshold. The effective threshold SHALL be the greater of 180 seconds and the bot interval plus 30 seconds.

#### Scenario: Stale bot appears

- **WHEN** a signed-in user opens the dashboard and a running bot has no successful tick within the effective stale threshold, and the engine has been up for at least that threshold
- **THEN** the bot appears with a stale badge, its last successful tick, and the reason

#### Scenario: Engine restart does not flash stale

- **WHEN** the engine restarts and its start time is within the stale threshold
- **THEN** a running bot with an older last successful tick is not shown as stale

#### Scenario: Older engine omits start time

- **WHEN** the heartbeat has no engine start time
- **THEN** the dashboard does not show a stale badge for any bot

#### Scenario: Unavailable heartbeat is not a stale signal

- **WHEN** the engine heartbeat is missing or expired
- **THEN** the dashboard does not mark bots stale solely because the engine is unavailable

#### Scenario: Threshold boundary is consistent

- **WHEN** a bot's last successful tick is exactly at the effective threshold
- **THEN** the dashboard applies the same boundary as the engine and does not disagree about whether the bot is stale

### Requirement: Bot health resume

The triage section SHALL let an owner resume a bot paused by the engine. A paper bot SHALL resume from the dashboard in one action; a live bot SHALL use the existing live confirmation flow and SHALL NOT resume without the exact confirmation phrase. A resume issued for a bot paused by the engine whose desired status is already running SHALL be recorded in the bot's activity log. The dashboard SHALL clear a pending resume state on the next poll and report failure or lack of engine confirmation.

#### Scenario: Resume a paper bot

- **WHEN** the owner resumes a paper bot paused by the engine from the dashboard
- **THEN** Laravel sets the desired status to running, publishes the start command, and reports the outcome

#### Scenario: Live resume uses confirmation

- **WHEN** the owner chooses to resume a live bot paused by the engine
- **THEN** the dashboard directs the owner to the bot page's confirmation flow and sends no start command from the triage section

#### Scenario: Resume is recorded

- **WHEN** a resume is issued for a bot paused by the engine whose desired status is already running
- **THEN** the bot's activity log records the user resume

#### Scenario: Resume failure is reported

- **WHEN** a resume fails
- **THEN** the dashboard shows the failure and keeps the bot's previous state visible

#### Scenario: Resume remains unconfirmed

- **WHEN** the next poll still shows the bot paused after a resume request
- **THEN** the dashboard clears the pending state and reports that the engine has not confirmed the resume

#### Scenario: Another user cannot resume

- **WHEN** a user attempts to resume a bot they do not own
- **THEN** the request is denied and no command is sent

### Requirement: Global engine status bar

The authenticated layout SHALL show a status bar on every authenticated page with the engine heartbeat state, market-data feed state, and live-trading state. It SHALL poll the engine health endpoint at most once every ten seconds per tab, including across navigation, and SHALL distinguish live, REST fallback, idle, off, and unknown feed states. A missing heartbeat SHALL be a warning only; feed and live states SHALL then be unknown or explicitly last-known.

#### Scenario: Engine is up

- **WHEN** a signed-in user views any authenticated page and the heartbeat is fresh
- **THEN** the status bar shows the engine as up with its last-seen time

#### Scenario: Engine is down

- **WHEN** the heartbeat is missing or expired
- **THEN** the status bar shows the engine as unavailable with the last-seen time or "never", does not imply that bots are paused, and shows feed and live states as unknown or last-known

#### Scenario: Feed is live

- **WHEN** the heartbeat reports websocket mode without degradation
- **THEN** the status bar shows the feed as live

#### Scenario: Feed is degraded

- **WHEN** the heartbeat reports REST fallback or degradation
- **THEN** the status bar shows the REST fallback state and the reason

#### Scenario: Feed is idle

- **WHEN** the heartbeat reports a configured feed with no subscribed symbols
- **THEN** the status bar shows the feed as idle rather than off

#### Scenario: Feed is off

- **WHEN** the heartbeat reports the feed as not configured
- **THEN** the status bar shows the feed as off and indicates ticks use REST snapshots

#### Scenario: Partial feed status is unknown

- **WHEN** the heartbeat's market-data object is present but its configured or degraded state is malformed
- **THEN** the status bar shows the feed state as unknown rather than off or live

#### Scenario: Live trading is on

- **WHEN** the heartbeat reports live trading enabled
- **THEN** the status bar shows a prominent LIVE ON warning

#### Scenario: Live trading is off

- **WHEN** the heartbeat reports live trading disabled
- **THEN** the status bar shows that live orders are blocked by the gate rather than merely showing live trading as off

#### Scenario: Older engine omits status

- **WHEN** the heartbeat omits the feed or live-trading fields
- **THEN** the status bar shows those states as unknown rather than off

#### Scenario: Polling is bounded across navigation

- **WHEN** the user navigates between authenticated pages repeatedly
- **THEN** the status bar does not request the engine health endpoint more than once every ten seconds
