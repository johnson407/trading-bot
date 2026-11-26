# deployment Specification

## Purpose

Defines how the trading bot is tested, packaged, published, and deployed to an outbound-only QNAP NAS, including health-gated activation, failure holds, and rollback.

## Requirements

### Requirement: Continuous integration gate

Every pull request and every push to the production branch SHALL run the automated test suite. A production image SHALL be built and published only for a commit whose tests passed. A failing test SHALL prevent image publication.

#### Scenario: Pull request runs tests

- **WHEN** a pull request is opened or updated
- **THEN** the test suite runs and no production image is published

#### Scenario: Green production branch publishes

- **WHEN** tests pass for a push to the production branch
- **THEN** the production images are built and published

#### Scenario: Failing production branch is blocked

- **WHEN** tests fail for a push to the production branch
- **THEN** no image is published for that commit

### Requirement: Production application image

The production application image SHALL contain the application code, production PHP dependencies, and compiled frontend assets. It SHALL NOT depend on host `vendor`, `node_modules`, or a source bind mount at runtime. The existing development image and bind-mounted development workflow SHALL remain available.

#### Scenario: Build from a clean checkout

- **WHEN** the production image is built from a clean checkout without installed dependencies
- **THEN** the build installs production dependencies and compiles frontend assets inside the image

#### Scenario: Run without a source mount

- **WHEN** the production application container starts
- **THEN** it runs from code baked into the image rather than a host source directory

#### Scenario: Development workflow remains usable

- **WHEN** a developer starts the existing development composition
- **THEN** the bind-mounted application and its development dependencies continue to work

### Requirement: Immutable image publication

Each successful production build SHALL publish an application image and a matching web-server image to the private registry, tagged with the full commit SHA and with `latest`. Both images for a tag SHALL be available before that tag can be activated. The web-server image SHALL serve the compiled public assets from the matching application image.

#### Scenario: Commit and latest tags

- **WHEN** a production build succeeds for a commit
- **THEN** all three images are published with the full commit SHA and with `latest`

#### Scenario: Incomplete image set is not activated

- **WHEN** the application image exists but the matching web-server image does not
- **THEN** the deployer does not activate that tag

#### Scenario: Assets match the application build

- **WHEN** a tag is activated
- **THEN** the web server serves public assets produced by that same application build

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

### Requirement: Migration ownership

Database migrations and public storage linking SHALL run only in the application service before it reports healthy. Worker and scheduler services SHALL wait for the application health and SHALL NOT run migrations. A production start SHALL fail clearly when the application key is missing.

#### Scenario: Application migrates before healthy

- **WHEN** the application service starts a new deployment
- **THEN** it applies pending migrations and links public storage before becoming healthy

#### Scenario: Worker and scheduler do not migrate

- **WHEN** the worker and scheduler services start
- **THEN** they wait for the application to be healthy and do not run migrations

#### Scenario: Missing application key fails fast

- **WHEN** the production application starts without an application key
- **THEN** startup fails with a clear error instead of generating or accepting an unstable key

### Requirement: Outbound deployment

Deployment SHALL be initiated from the NAS using only outbound registry and network access. A scheduled task SHALL poll for the desired image tag and SHALL pull and activate a new image without requiring an inbound connection to the NAS.

#### Scenario: Scheduled poll

- **WHEN** the scheduled deployment task runs
- **THEN** it checks the desired tag and pulls only when a different image is available

#### Scenario: No inbound access required

- **WHEN** the NAS has no public address, forwarded port, or tunnel
- **THEN** deployment still proceeds using outbound registry access

### Requirement: Health-gated activation

A deployment SHALL be considered successful only when all seven services are healthy within 180 seconds of activation. Failure of the application, web server, engine, worker, or scheduler SHALL be treated as an application-tier failure. Failure of PostgreSQL or Redis SHALL be treated as an infrastructure failure and retried without image rollback.

#### Scenario: All services become healthy

- **WHEN** all seven services report healthy within the activation window
- **THEN** the deployment is recorded as successful

#### Scenario: Application-tier failure rolls back

- **WHEN** the application, web server, engine, worker, or scheduler does not become healthy in time
- **THEN** the deployer rolls back the application, web-server, and engine images

#### Scenario: Infrastructure failure retries

- **WHEN** PostgreSQL or Redis is unhealthy during activation
- **THEN** the deployer reports an infrastructure failure and retries on a later poll without changing images

### Requirement: Failed-image hold

After an application-tier failure, the deployer SHALL record the failed image identity in a hold state and SHALL skip redeploying that same image on subsequent polls. The hold SHALL clear when a different image becomes desired or an operator explicitly clears it. A pinned desired tag SHALL persist across polls and NAS reboots.

#### Scenario: Same failed image is skipped

- **WHEN** the failed image is still the desired image on the next poll
- **THEN** the deployer skips activation and records that it is held

#### Scenario: New image clears the hold

- **WHEN** a different image becomes the desired image
- **THEN** the deployer attempts the new image

#### Scenario: Operator clears the hold

- **WHEN** an operator explicitly clears the hold for the failed image
- **THEN** the next poll retries that image

#### Scenario: Pin survives a reboot

- **WHEN** an operator pins a specific tag and the NAS reboots
- **THEN** later polls continue to use the pinned tag

### Requirement: Rollback

On application-tier failure, the deployer SHALL restore the last fully healthy application, web-server, and engine images without pulling an ephemeral tag. It SHALL NOT attempt to roll back database schema or data. The last-good record SHALL be updated only after a fully healthy deployment.

#### Scenario: Restore the previous images

- **WHEN** a new deployment fails at the application tier
- **THEN** the deployer restores the last fully healthy application and web-server images

#### Scenario: Database is not rolled back

- **WHEN** the deployer rolls back images
- **THEN** database schema and data are left as they are

#### Scenario: Failed deployment does not become last-good

- **WHEN** a deployment fails health checks
- **THEN** the last-good record continues to point at the previous fully healthy deployment

### Requirement: Operator controls

Operators SHALL be able to pin a specific image tag, return to the latest image, clear a hold, and request rollback to the last fully healthy deployment. Deployment state and logs SHALL persist across NAS reboots.

#### Scenario: Pin a tag

- **WHEN** an operator pins a specific image tag
- **THEN** subsequent deployments use that tag instead of `latest`

#### Scenario: Return to latest

- **WHEN** an operator returns to the latest image
- **THEN** the pin and any active hold are cleared

#### Scenario: Request rollback

- **WHEN** an operator requests rollback
- **THEN** the deployer activates the last fully healthy deployment

#### Scenario: State survives reboot

- **WHEN** the NAS reboots
- **THEN** deployment state and logs remain available

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

### Requirement: Registry access

The NAS SHALL authenticate to the private registry with read-only package credentials sufficient to pull images. Registry credentials SHALL NOT grant write access to packages or access to repository contents.

#### Scenario: Pull with read-only credentials

- **WHEN** the NAS pulls a private image with its registry credential
- **THEN** the pull succeeds

#### Scenario: Invalid credentials fail clearly

- **WHEN** the registry credential is missing, expired, or lacks package read access
- **THEN** the deployer reports an authentication failure and does not activate an image

#### Scenario: Write access is not required

- **WHEN** the NAS credential is used
- **THEN** it cannot publish or modify registry packages

### Requirement: Preserved trading behavior

This change SHALL NOT alter grid, strategy, risk, exchange, or bot-runtime behavior. Live trading SHALL remain disabled unless explicitly enabled in the production configuration.

#### Scenario: Existing behavior is unchanged

- **WHEN** the change is deployed
- **THEN** existing bot, strategy, risk, and exchange tests continue to pass

#### Scenario: Live trading stays off by default

- **WHEN** production configuration does not explicitly enable live trading
- **THEN** bots operate in paper mode only

### Requirement: Deployment runbook

Operators SHALL have a runbook covering prerequisites, first installation, registry authentication, scheduled polling, updates, holds, rollback, and recovery. The runbook SHALL identify the persistent paths used for configuration, state, and logs.

#### Scenario: First installation

- **WHEN** an operator follows the runbook on a supported NAS
- **THEN** the stack starts from published images and becomes healthy

#### Scenario: Recover from a held deployment

- **WHEN** an operator follows the runbook after an application-tier failure
- **THEN** they can inspect the failure, clear or retain the hold, and restore service
