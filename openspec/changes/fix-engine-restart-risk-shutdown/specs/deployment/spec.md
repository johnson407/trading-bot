# Spec Delta

## MODIFIED Requirements

### Requirement: Production composition

The production composition SHALL run the application, web server, queue worker, scheduler, PostgreSQL, and Redis without source bind mounts. Persistent application storage, database data, and Redis data SHALL use named volumes. Only the web server SHALL publish a host port. The engine service SHALL have a stop grace period at least as long as its shutdown drain timeout.

#### Scenario: No source code on the host

- **WHEN** the production composition is running
- **THEN** no service mounts the repository source directory

#### Scenario: Data survives container replacement

- **WHEN** the application containers are recreated for a new image
- **THEN** application storage, database data, and Redis data remain in their named volumes

#### Scenario: Only the web server is exposed

- **WHEN** the production composition publishes ports
- **THEN** only the web server publishes a host port

#### Scenario: Engine drains on stop

- **WHEN** the engine service receives a stop request
- **THEN** Docker waits at least the engine drain timeout before forcing termination
