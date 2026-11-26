# Spec Delta

## MODIFIED Requirements

### Requirement: Production composition

The production composition SHALL run the application, web server, engine, queue worker, scheduler, PostgreSQL, and Redis without source bind mounts. Persistent application storage, database data, and Redis data SHALL use named volumes. Only the web server SHALL publish a host port. Each of the seven services SHALL rotate its JSON container log with a bounded size and file count and SHALL declare a memory limit.

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

- **WHEN** any of the seven production services writes container logs over time
- **THEN** its `json-file` log rotates at the configured maximum size and retains only the configured number of files

#### Scenario: Memory is bounded

- **WHEN** any of the seven production services approaches its configured memory limit
- **THEN** the container runtime constrains or restarts that service instead of allowing it to exhaust the host

### Requirement: Runtime configuration and secrets

Production secrets and environment configuration SHALL be supplied at runtime and SHALL NOT be baked into images. The application key SHALL remain stable across deployments. The production database SHALL start empty, and development data or exchange credentials SHALL NOT be copied into it. The production composition SHALL require a non-empty internal engine token, and the engine SHALL refuse to start before any trading worker when its listener is reachable beyond loopback without a token.

#### Scenario: Secrets are not in the image

- **WHEN** a production image is inspected
- **THEN** it contains no production application key, database password, exchange credential, or registry token

#### Scenario: Key remains stable

- **WHEN** a new image is deployed
- **THEN** the existing application key is reused so encrypted data remains readable

#### Scenario: Fresh production database

- **WHEN** the production database is first created
- **THEN** it contains no development rows, and the first account is created through registration or an explicit command

#### Scenario: Missing token blocks the composition

- **WHEN** the production composition is started without `ENGINE_INTERNAL_TOKEN`
- **THEN** Compose rejects the configuration before any container starts

#### Scenario: Non-Compose engine fails closed before trading

- **WHEN** a production engine starts outside the production composition with a listener reachable beyond loopback and no internal token
- **THEN** it exits with a configuration error before starting the command consumer, scheduler, market feed, or any tick

#### Scenario: Token is supplied at runtime

- **WHEN** the production composition is configured with an internal token
- **THEN** Laravel and the engine receive the same token from runtime configuration, and the engine accepts an authenticated plan request
