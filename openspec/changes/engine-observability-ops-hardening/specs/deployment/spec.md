# Spec Delta

## MODIFIED Requirements

### Requirement: Production composition

The production composition SHALL run the application, web server, queue worker, scheduler, PostgreSQL, and Redis without source bind mounts. Persistent application storage, database data, and Redis data SHALL use named volumes. Only the web server SHALL publish a host port. Each service SHALL rotate its JSON container log with a bounded size and file count and SHALL declare a memory limit.

#### Scenario: No source code on the host

- **WHEN** the production composition is running
- **THEN** no service mounts the repository source directory

#### Scenario: Data survives container replacement

- **WHEN** the application containers are recreated for a new image
- **THEN** application storage, database data, and Redis data remain in their named volumes

#### Scenario: Only the web server is exposed

- **WHEN** the production composition publishes ports
- **THEN** only the web server publishes a host port

#### Scenario: Container logs are bounded

- **WHEN** a production service writes container logs over time
- **THEN** its JSON log rotates at the configured maximum size and retains only the configured number of files

#### Scenario: Memory is bounded

- **WHEN** a production service approaches its configured memory limit
- **THEN** the container runtime constrains or restarts that service instead of allowing it to exhaust the host

### Requirement: Runtime configuration and secrets

Production secrets and environment configuration SHALL be supplied at runtime and SHALL NOT be baked into images. The application key SHALL remain stable across deployments. The production database SHALL start empty, and development data or exchange credentials SHALL NOT be copied into it. The production engine SHALL require a non-empty internal token before serving its plan endpoint when the listener is reachable beyond loopback.

#### Scenario: Secrets are not in the image

- **WHEN** a production image is inspected
- **THEN** it contains no production application key, database password, exchange credential, or registry token

#### Scenario: Key remains stable

- **WHEN** a new image is deployed
- **THEN** the existing application key is reused so encrypted data remains readable

#### Scenario: Fresh production database

- **WHEN** the production database is first created
- **THEN** it contains no development rows, and the first account is created through registration or an explicit command

#### Scenario: Missing internal token fails closed

- **WHEN** the production engine starts with a listener reachable beyond loopback and no internal token
- **THEN** it refuses plan requests and reports the configuration error instead of serving an unauthenticated endpoint

#### Scenario: Token is supplied at runtime

- **WHEN** the production composition is configured with an internal token
- **THEN** Laravel and the engine receive the same token from runtime configuration, and the engine accepts an authenticated plan request
